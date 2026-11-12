<?php

namespace App\Support\StatusPages;

use App\Enums\Limit;
use App\Mail\StatusPageUpdate;
use App\Mail\StatusSubscribeConfirm;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\StatusSubscriber;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Mail\Unsubscribes;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

final class StatusSubscriptions
{
    private const RESEND_AFTER_MINUTES = 60;

    /** @throws RuntimeException */
    public static function subscribe(StatusPage|WorkspaceStatusPage $page, string $email): StatusSubscriber
    {
        $email = Unsubscribes::normalise($email);
        $subscriber = $page->subscribers()->where('email', $email)->first();

        if ($subscriber === null) {
            $workspace = self::workspaceOf($page);
            $limit = $workspace->isActive() ? $workspace->limitOf(Limit::StatusSubscribers) : 0;
            if ($limit !== null && $page->subscribers()->count() >= $limit) {
                throw new RuntimeException('This status page can’t take more subscribers right now.');
            }
            $subscriber = $page->subscribers()->create(['email' => $email, 'token' => Str::random(48)]);
        }

        $due = $subscriber->confirm_sent_at === null || $subscriber->confirm_sent_at->lt(now()->subMinutes(self::RESEND_AFTER_MINUTES));
        if ($subscriber->confirmed_at === null && $due) {
            $subscriber->forceFill(['confirm_sent_at' => now()])->save();
            $subscriber->setRelation('subscribable', $page);
            Mail::to($email)->queue(new StatusSubscribeConfirm($subscriber, self::title($page)));
        }

        return $subscriber;
    }

    public static function incident(StatusIncident $incident): void
    {
        $update = $incident->updates()->first();
        if ($update === null) {
            return;
        }

        $subject = match (true) {
            $incident->state === 'resolved' => "Resolved: {$incident->title}",
            $incident->updates()->count() === 1 => $incident->title,
            default => "Update: {$incident->title}",
        };
        $states = ['investigating' => 'Investigating', 'identified' => 'Identified', 'monitoring' => 'Monitoring', 'resolved' => 'Resolved'];

        self::send(self::pagesForIncident($incident), $subject, $incident->title, $states[$update->state] ?? $update->state, $update->message);
    }

    public static function outage(Monitor $monitor, ?MonitorIncident $incident, bool $down): void
    {
        $pages = self::pagesForMonitor($monitor);
        if ($pages === []) {
            return;
        }

        if ($down) {
            self::send($pages, "{$monitor->name} is down", "{$monitor->name} is down", 'Down', 'Our checks can’t reach it right now. We’ll email again when it’s back.');
        } else {
            $minutes = $incident ? max(1, (int) round($incident->started_at->diffInSeconds(now()) / 60)) : null;
            self::send($pages, "{$monitor->name} is back up", "{$monitor->name} is back up", 'Resolved', $minutes ? "It’s answering again, after {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').' down.' : 'It’s answering again.');
        }
    }

    /** @return list<StatusPage|WorkspaceStatusPage> */
    public static function pagesForIncident(StatusIncident $incident): array
    {
        $pages = [];
        $machinePage = $incident->machine_id ? StatusPage::query()->where('machine_id', $incident->machine_id)->first() : null;
        if ($machinePage !== null && $machinePage->live() && $machinePage->public) {
            $pages[] = $machinePage;
        }

        $fleet = $incident->workspace->fleetStatusPage;
        if ($fleet !== null && $fleet->live() && $fleet->public
            && ($incident->machine === null || $fleet->covers($incident->machine))) {
            $pages[] = $fleet;
        }

        return $pages;
    }

    /** @return list<StatusPage|WorkspaceStatusPage> */
    public static function pagesForMonitor(Monitor $monitor): array
    {
        $pages = [];
        $machine = $monitor->machine;

        if ($machine !== null) {
            $page = $machine->statusPage;
            if ($page !== null && $page->live() && $page->public && self::lists(new StatusReport($page), $monitor)) {
                $pages[] = $page;
            }
        }

        $fleet = $monitor->workspace->fleetStatusPage;
        if ($fleet !== null && $fleet->live() && $fleet->public) {
            $shown = $machine !== null
                ? $fleet->covers($machine) && self::lists(new StatusReport(FleetStatusReport::pageFor($machine, $fleet->show_uptime)), $monitor)
                : in_array($monitor->id, $fleet->monitors ?? [], true);
            if ($shown) {
                $pages[] = $fleet;
            }
        }

        return $pages;
    }

    private static function lists(StatusReport $report, Monitor $monitor): bool
    {
        return in_array('monitor:'.$monitor->id, array_column($report->monitors(), 'key'), true);
    }

    /** @param  list<StatusPage|WorkspaceStatusPage>  $pages */
    private static function send(array $pages, string $subject, string $heading, string $state, string $message): void
    {
        $sent = [];
        foreach ($pages as $page) {
            foreach ($page->subscribers()->whereNotNull('confirmed_at')->cursor() as $subscriber) {
                if (isset($sent[$subscriber->email]) || Unsubscribes::has($subscriber->email)) {
                    continue;
                }
                $sent[$subscriber->email] = true;
                $subscriber->setRelation('subscribable', $page);
                Mail::to($subscriber->email)->queue(new StatusPageUpdate($subscriber, self::title($page), $subject, $heading, $state, $message));
            }
        }
    }

    public static function title(StatusPage|WorkspaceStatusPage $page): string
    {
        return $page->title ?: ($page instanceof WorkspaceStatusPage ? $page->workspace->name : $page->machine->name);
    }

    private static function workspaceOf(StatusPage|WorkspaceStatusPage $page): Workspace
    {
        return $page instanceof WorkspaceStatusPage ? $page->workspace : $page->machine->workspace;
    }
}
