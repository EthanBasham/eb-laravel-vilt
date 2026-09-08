<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const auth = computed(() => page.props.auth);
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div class="flex min-h-screen flex-col bg-gray-50">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-3 sm:px-6 lg:px-8">
                <Link href="/wot" class="text-lg font-semibold text-brand-600">
                    World of Tanks
                    <span class="ms-1 text-sm font-normal text-gray-500">dashboard</span>
                </Link>

                <nav class="flex items-center gap-4 text-sm" aria-label="Account">
                    <span v-if="auth.wot" class="font-medium text-gray-700">{{ auth.wot.nickname }}</span>
                    <!-- Back to the Blade half of the application. A plain <a>,
                         not an Inertia <Link>: that route is server-rendered and
                         outside this SPA, so it needs a real page load. -->
                    <a href="/" class="text-gray-500 hover:text-gray-700">Leave dashboard</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            <p v-if="flash.success" class="mb-6 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
                {{ flash.success }}
            </p>
            <p v-if="flash.error" class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {{ flash.error }}
            </p>

            <slot />
        </main>
    </div>
</template>
