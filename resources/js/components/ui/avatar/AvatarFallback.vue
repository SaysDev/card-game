<script setup lang="ts">
import { cn } from '@/lib/utils'
import { AvatarFallback, type AvatarFallbackProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'
<script setup lang="ts">
interface Props {
  class?: string;
}

const props = defineProps<Props>();
</script>

<template>
  <div
    :class="[
      'flex h-full w-full items-center justify-center rounded-full bg-gray-200 dark:bg-gray-800',
      props.class || ''
    ]"
  >
    <slot />
  </div>
</template>
const props = defineProps<AvatarFallbackProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>

<template>
  <AvatarFallback
    data-slot="avatar-fallback"
    v-bind="delegatedProps"
    :class="cn('bg-muted flex size-full items-center justify-center rounded-full', props.class)"
  >
    <slot />
  </AvatarFallback>
</template>
