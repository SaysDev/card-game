<template>
  <MobileGameLayout>
    <div class="flex-grow flex flex-col items-center justify-center relative w-full h-full">
            <transition-group name="v">
               <div key="events" class="w-full h-full p-4">
                <h2 class="text-3xl font-bold text-center mb-4">Wydarzenia</h2>
                <div class="space-y-4">
                  <div class="p-4 rounded-lg bg-black/20">
                    <h3 class="font-bold text-lg text-yellow-400">Festiwal Królowej</h3>
                    <p class="text-sm opacity-80">Zagraj 10 Dam, aby odblokować nagrody!</p>
                  </div>
                  <div class="p-4 rounded-lg bg-black/20">
                    <h3 class="font-bold text-lg text-green-400">Weekendowa Passa</h3>
                    <p class="text-sm opacity-80">Wygraj 5 gier z rzędu.</p>
                  </div>
                </div>
              </div>

            </transition-group>
          </div>
  </MobileGameLayout>
</template>

<script setup lang="ts">
import MobileGameLayout from '@/layouts/MobileGameLayout.vue';

const startGameFlow = () => {

};
</script> 
  