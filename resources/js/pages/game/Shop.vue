<template>
    <MobileGameLayout>
        <div class="flex-grow flex flex-col items-center justify-center relative w-full h-full">
            <transition-group name="v">
                <div  key="shop" class="w-full h-full p-4"><h2 class="text-3xl font-bold text-center mb-4">Sklep</h2><div class="grid grid-cols-2 gap-4"><div class="p-3 rounded-lg bg-black/20 text-center flex flex-col items-center justify-center"><svg class="w-16 h-16 text-yellow-400" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-12h2v4h-2zm0 6h2v2h-2z" opacity="0.5"/><path d="M12 4c-4.41 0-8 3.59-8 8s3.59 8 8 8 8-3.59 8-8-3.59-8-8-8zm0 1.5c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5S10.5 8.33 10.5 7.5 11.17 6 12 6zm4 10.5H8v-1.53c0-.66.27-1.26.7-1.7l1.76-1.76c.44-.44 1.04-.71 1.69-.71s1.25.27 1.69.71l1.76 1.76c.43.44.7 1.04.7 1.7V16.5z"/></svg><p>1000 monet</p><button class="bg-green-500 rounded-lg px-4 py-1 mt-2">1.99zł</button></div><div class="p-3 rounded-lg bg-black/20 text-center flex flex-col items-center justify-center"><svg class="w-16 h-16 text-purple-400" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5-10-5-10 5z" opacity="0.5"/><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5-10-5-10 5zM2 12l10 5 10-5-10-5-10 5z"/></svg><p>500 gemów</p><button class="bg-green-500 rounded-lg px-4 py-1 mt-2">4.99zł</button></div></div></div>
                


            </transition-group>
        </div>
    </MobileGameLayout>
</template>

<script setup lang="ts">
import MobileGameLayout from '@/layouts/MobileGameLayout.vue';

const startGameFlow = () => {

};
</script>