<template>
    <MobileGameLayout>
        <div class="flex-grow flex flex-col items-center justify-center relative w-full h-full">
            <transition-group name="v">
                <div key="friends" class="w-full h-full p-4">
                    <h2 class="text-3xl font-bold text-center mb-4">Znajomi</h2>
                    <ul class="space-y-2">
                        <li class="flex items-center justify-between p-2 bg-black/20 rounded-lg"><span>🧑‍🚀 Mark
                                K.</span><span class="text-green-400 text-xs font-bold">Online</span></li>
                        <li class="flex items-center justify-between p-2 bg-black/30 rounded-lg"><span>🦹‍♀️ Kevin
                                J.</span><span class="text-gray-400 text-xs">Offline</span></li>
                    </ul>
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