<script setup lang="ts">
import type { AvatarImageProps } from 'reka-ui'
import { AvatarImage } from 'reka-ui'

const props = defineProps<AvatarImageProps>()
</script>

<template>
  <AvatarImage
    data-slot="avatar-image"
    v-bind="props"
    class="aspect-square size-full"
  >
    <slot />
  </AvatarImage>
</template>
<script setup lang="ts">
interface Props {
  src: string;
  alt?: string;
  class?: string;
}

const props = defineProps<Props>();
</script>

<template>
  <img :src="src" :alt="alt || ''" :class="class || ''" class="h-full w-full object-cover" />
</template>