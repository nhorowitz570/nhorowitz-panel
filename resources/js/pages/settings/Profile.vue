<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Profile settings'),
                href: edit(),
            },
        ],
    },
});

const props = defineProps<{
    hasPassword?: boolean;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const email = ref(user.value.email);
const changingEmail = computed(
    () =>
        props.hasPassword &&
        email.value.trim().toLowerCase() !== user.value.email.toLowerCase(),
);
</script>

<template>
    <Head :title="$t('Profile settings')" />

    <h1 class="sr-only">{{ $t('Profile settings') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="$t('Profile')"
            :description="$t('Update your name and email address')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="$t('Full name')"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    v-model="email"
                    required
                    autocomplete="username"
                    :placeholder="$t('Email address')"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="changingEmail" class="grid gap-2">
                <Label for="current_password">{{ $t('Your password') }}</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    :placeholder="
                        $t('Enter your password to change your email')
                    "
                />
                <InputError class="mt-2" :message="errors.current_password" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    {{ ' ' + $t('Your email address is unverified.') }}
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        {{
                            ' ' +
                            $t(
                                'Click here to re-send the verification email.',
                            ) +
                            ' '
                        }}
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    {{
                        ' ' +
                        $t(
                            'A new verification link has been sent to your email address.',
                        ) +
                        ' '
                    }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                    >{{ $t('Save') }}</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
