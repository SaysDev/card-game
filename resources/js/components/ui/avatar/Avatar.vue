<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { AvatarRoot } from 'reka-ui'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <AvatarRoot
    data-slot="avatar"
    :class="cn('relative flex size-8 shrink-0 overflow-hidden rounded-full', props.class)"
  >
    <slot />
  </AvatarRoot>
</template>
<script setup lang="ts">
import { computed } from 'vue';

interface Props {
  class?: string;
}

const props = defineProps<Props>();

const classes = computed(() => {
  return `inline-flex items-center justify-center overflow-hidden rounded-full ${props.class || ''}`;
});
</script>

<template>
  <div :class="classes">
    <slot />
  </div>
</template>