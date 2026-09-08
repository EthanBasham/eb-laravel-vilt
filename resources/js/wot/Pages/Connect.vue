<script setup>
import { Head } from '@inertiajs/vue3';
import AppShell from '../Components/AppShell.vue';
</script>

<template>
    <Head title="Connect your account" />

    <AppShell>
        <div class="mx-auto max-w-xl rounded-lg border border-gray-200 bg-white p-8 text-center">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Connect your Wargaming account</h1>

            <p class="mt-3 text-sm leading-relaxed text-gray-600">
                You'll be sent to Wargaming to sign in. This application never sees your password — it
                receives an access token, valid for two weeks, which is stored encrypted and can be
                revoked here at any time.
            </p>

            <!-- A real link, not an Inertia visit: this navigates off-origin to
                 Wargaming's own login page. -->
            <a
                href="/wot/connect"
                class="mt-6 inline-flex items-center rounded-md bg-brand-600 px-5 py-3 text-sm font-medium text-white hover:bg-brand-700"
            >
                Sign in with Wargaming
            </a>
        </div>
    </AppShell>
</template>
