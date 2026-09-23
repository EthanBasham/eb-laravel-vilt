<script setup>
/**
 * What the letters in a crew cell mean.
 *
 * This legend earns its place more than any other on the site: a cell is five
 * letters, and every other thing it says is said by how they are drawn.
 *
 * Grouped by what a cue applies to, broadest first — the order the eye takes a
 * cell in. Colour reads from across the board, then the shape of the whole set,
 * then the marks on a single letter. It used to be one list flowed into three
 * columns, which split the four colours over two rows and put "whole set maxed"
 * nowhere near "member maxed".
 *
 * Maxed leads both the Set and Member rows, so the two scopes of the same fact
 * sit one above the other; the member row then runs through zeroed steps, one
 * then two, and skills trained. Each row wears the same label column as the
 * filters above it.
 */
</script>

<template>
    <div class="space-y-1 border-t border-wot-border-soft pt-2 text-xs text-wot-dim">
        <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
            <span class="w-12 shrink-0 font-bold uppercase tracking-wider">Colour</span>
            <dl class="contents">
                <div class="flex items-baseline gap-2">
                    <dt class="font-bold text-wot-bad">C G D</dt>
                    <dd>no crew in the tank</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="font-bold text-wot-heading">C G D</dt>
                    <dd>none zero-skill</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="font-bold text-wot-gold">C G D</dt>
                    <dd>some zero-skill</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="font-bold text-wot-good">C G D</dt>
                    <dd>all zero-skill</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
            <span class="w-12 shrink-0 font-bold uppercase tracking-wider">Set</span>
            <dl class="contents">
                <div class="flex items-baseline gap-2">
                    <dt class="bg-wot-good/15 px-1 font-bold text-wot-good">C G D</dt>
                    <dd>every member maxed</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="italic text-wot-text">C G D</dt>
                    <dd>not well balanced</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
            <span class="w-12 shrink-0 font-bold uppercase tracking-wider">Member</span>
            <dl class="contents">
                <div class="flex items-baseline gap-2">
                    <dt class="font-bold text-wot-text">C</dt>
                    <dd>maxed</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="text-wot-text"><span class="underline underline-offset-2">C</span></dt>
                    <dd>one zeroed XP step</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="text-wot-text"><span class="underline decoration-double underline-offset-2">C</span></dt>
                    <dd>two zeroed XP steps</dd>
                </div>
                <div class="flex items-baseline gap-2">
                    <dt class="text-wot-text">C<sup class="text-[0.65em] opacity-70">4</sup></dt>
                    <dd>skills trained</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
