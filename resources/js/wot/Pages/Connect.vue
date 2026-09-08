<script setup>
import { Head } from '@inertiajs/vue3';
import AppShell from '../Components/AppShell.vue';
</script>

<template>
    <Head title="Connect your account" />

    <AppShell>
        <div class="mx-auto max-w-xl border border-wot-border bg-wot-panel p-8 text-center">
            <h1 class="text-2xl">Connect your Wargaming account</h1>

            <p class="mt-4 text-sm leading-relaxed text-wot-text">
                You'll be sent to Wargaming to sign in. This application never sees your password — it
                receives an access token, valid for two weeks, which is stored encrypted and can be
                revoked here at any time.
            </p>

            <!-- A real link, not an Inertia visit: this navigates off-origin to
                 Wargaming's own login page. -->
            <a
                href="/wot/connect"
                class="mt-8 inline-flex items-center border border-wot-gold bg-wot-gold px-6 py-3 text-sm font-bold uppercase tracking-wider text-wot-abyss transition-colors hover:bg-wot-gold-bright hover:border-wot-gold-bright"
            >
                Sign in with Wargaming
            </a>

            <p class="mt-6 text-xs text-wot-dim">North America realm</p>
        </div>
    </AppShell>
</template>
