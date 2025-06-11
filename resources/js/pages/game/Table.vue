<script setup lang="ts">
import { ref, onMounted } from 'vue';
import GameBoard from '@/components/game/GameBoard.vue';
import { useToast } from '@/components/ui/toast';
import { webSocketService } from '@/Services/WebSocketService';
import { MessageType } from '@/types/messageTypes';

const roomId = ref<string | null>(null);
const { toast } = useToast();

onMounted(() => {
    // Get room_id from URL query parameter
    const urlParams = new URLSearchParams(window.location.search);
    roomId.value = urlParams.get('room_id');
    
    if (!roomId.value) {
        toast({
            title: 'Błąd',
            description: 'Brak identyfikatora pokoju.',
            variant: 'destructive',
        });
        return;
    }
    
    // Ensure connection to WebSocket server
    if (!webSocketService.isConnected) {
        webSocketService.connect().then(() => {
            console.log('Connected to WebSocket server, room ID:', roomId.value);
        });
    }
});
</script>

<template>
    <div id="app" class="min-h-screen">
        <div v-if="!roomId" class="flex items-center justify-center min-h-screen bg-gray-100 dark:bg-gray-900">
            <div class="p-6 bg-white dark:bg-gray-800 rounded-lg shadow-lg text-center">
                <h2 class="text-xl font-semibold mb-4">Ładowanie gry...</h2>
                <p>Trwa inicjalizacja połączenia z serwerem gry.</p>
            </div>
        </div>
        <game-board v-else />
    </div>
</template>

<style>
body {
    font-family: 'Inter', sans-serif;
    margin: 0;
    padding: 0;
}
</style>
