<template>
    <component
        :is="card ? BaseCard : 'div'"
        :class="[
            'relative flex w-full flex-col overflow-hidden',
            card ? '!p-0' : '',
            containerHeightClass,
        ]"
    >
        <slot name="header" />

        <div
            class="sticky top-0 z-10 border-b bg-white/80 backdrop-blur"
            role="tablist"
            aria-label="Sections"
            ref="tablistRef"
        >
            <div class="flex items-stretch gap-1 overflow-x-auto px-4 pb-2 pt-3 no-scrollbar">
                <button
                    v-for="(tab, index) in tabs"
                    :key="tab.id"
                    type="button"
                    role="tab"
                    class="group relative shrink-0 rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-stone-500 transition hover:text-stone-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 aria-selected:border-stone-900 aria-selected:text-stone-900"
                    :id="`tab-${tab.id}`"
                    :tabindex="tab.id === internalActive ? '0' : '-1'"
                    :aria-selected="tab.id === internalActive ? 'true' : 'false'"
                    :aria-controls="`panel-${tab.id}`"
                    @click="setActive(tab.id)"
                    @keydown="onKeydown($event, index)"
                >
                    <span class="inline-flex items-center gap-2">
                        <component v-if="tab.icon" :is="tab.icon" class="h-4 w-4" aria-hidden="true" />
                        <span>{{ tab.label }}</span>
                        <span
                            v-if="tab.badge !== undefined"
                            class="rounded bg-stone-100 px-1.5 py-0.5 text-xs text-stone-600"
                        >
                            {{ tab.badge }}
                        </span>
                    </span>
                    <span
                        class="pointer-events-none absolute inset-x-0 -bottom-[2px] h-0.5 opacity-0 transition group-aria-selected:opacity-100"
                        :class="tabAccentClass"
                    />
                </button>
            </div>
        </div>

        <div class="relative grow overflow-hidden">
            <div class="h-full w-full overflow-auto p-4">
                <slot name="panels" :active="internalActive" />
            </div>
        </div>
    </component>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import BaseCard from './BaseCard.vue'

const props = defineProps({
    tabs: {
        type: Array,
        required: true,
    },
    modelValue: {
        type: [String, Number],
        default: null,
    },
    fitScreen: {
        type: Boolean,
        default: true,
    },
    card: {
        type: Boolean,
        default: true,
    },
    viewportOffset: {
        type: Number,
        default: 0,
    },
    accentClass: {
        type: String,
        default: 'bg-stone-900',
    },
})

const emit = defineEmits(['update:modelValue', 'change'])

const tablistRef = ref(null)
const internalActive = ref(props.modelValue ?? props.tabs[0]?.id ?? null)

watch(
    () => props.modelValue,
    (value) => {
        if (value && value !== internalActive.value) {
            internalActive.value = value
        }
    },
)

watch(
    () => props.tabs,
    (tabs) => {
        if (!tabs?.length) {
            internalActive.value = null
            return
        }

        const found = tabs.some((tab) => tab.id === internalActive.value)
        if (!found) {
            setActive(tabs[0].id)
        }
    },
    { deep: true },
)

const containerHeightClass = computed(() => {
    if (!props.fitScreen) {
        return 'h-full'
    }

    return props.viewportOffset
        ? `h-[calc(100vh-${props.viewportOffset}px)]`
        : 'h-screen'
})

const tabAccentClass = computed(() => props.accentClass)

function setActive(id) {
    if (id === internalActive.value) {
        return
    }

    internalActive.value = id
    emit('update:modelValue', id)
    emit('change', id)

    nextTick(() => {
        focusActiveTab()
    })
}

function focusActiveTab() {
    const element = tablistRef.value?.querySelector('[role="tab"][aria-selected="true"]')
    element?.focus({ preventScroll: true })
}

function onKeydown(event, index) {
    const count = props.tabs.length
    if (!count) {
        return
    }

    const move = (nextIndex) => {
        const clampedIndex = (nextIndex + count) % count
        setActive(props.tabs[clampedIndex].id)
    }

    switch (event.key) {
        case 'ArrowRight':
        case 'Right':
            event.preventDefault()
            move(index + 1)
            break
        case 'ArrowLeft':
        case 'Left':
            event.preventDefault()
            move(index - 1)
            break
        case 'Home':
            event.preventDefault()
            move(0)
            break
        case 'End':
            event.preventDefault()
            move(count - 1)
            break
        case 'Enter':
        case ' ':
            event.preventDefault()
            break
        default:
            break
    }
}

onMounted(() => {
    if (!internalActive.value && props.tabs[0]) {
        setActive(props.tabs[0].id)
    }
})
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
    display: none;
}

.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
