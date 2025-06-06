<script setup lang="ts">
import { cn } from '@/lib/utils'
import { AvatarFallback, type AvatarFallbackProps } from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

interface Props {
  class?: string;
}

const props = defineProps<Props>();
</script>

<template>
  <div
    class="flex h-full w-full items-center justify-center rounded-full bg-muted"
  >
    <slot />
  </div>
</template>

<script setup lang="ts">
defineOptions({
  inheritAttrs: false
});
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

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})
</script>
