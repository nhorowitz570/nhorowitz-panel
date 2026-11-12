<?php

namespace App\Support\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * @phpstan-type Row array{name: string, value?: string|null, dot?: 'good'|'warn'|'bad'|'neutral'|null, muted?: bool, tone?: 'good'|'warn'|'bad'|'mono'|null}
 * @phpstan-type Section array{title?: string|null, rows: list<Row>, more?: string|null}
 */
final class BrandMail
{
    /**
     * @param  array{image: string, heading: string, paragraphs?: list<string>, sections?: list<Section>, after?: list<string>, button?: array{label: string, url: string}|null, link?: array{label: string, url: string}|null, note?: string|null, signature?: string|null, footer?: list<string>, align?: 'center'|'left'}  $content
     */
    public static function apply(MailMessage $mail, array $content): MailMessage
    {
        return $mail->view(['html' => 'mail.brand', 'text' => 'mail.brand-text'], self::data($content) + array_intersect_key($mail->viewData, ['unsubscribe' => true]));
    }

    /**
     * @param  array{image: string, heading: string, brand?: string|null, paragraphs?: list<string>, sections?: list<Section>, after?: list<string>, button?: array{label: string, url: string}|null, link?: array{label: string, url: string}|null, note?: string|null, signature?: string|null, footer?: list<string>, align?: 'center'|'left', unsubscribe?: array{url: string, label: string}}  $content
     * @return array<string, mixed>
     */
    public static function data(array $content): array
    {
        $data = $content + [
            'brand' => null,
            'paragraphs' => [],
            'sections' => [],
            'after' => [],
            'button' => null,
            'link' => null,
            'note' => null,
            'signature' => null,
            'footer' => [],
            'align' => 'center',
        ];
        $data['imageUrl'] = asset('images/email/'.$content['image'].'.jpg');
        $data['iconUrl'] = asset('images/email/icon-'.$content['image'].'.png');
        $data['logoUrl'] = asset('images/email/logo.png');

        return $data;
    }
}
