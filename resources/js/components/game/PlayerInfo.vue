<script setup lang="ts">
import { defineProps } from 'vue';

interface PlayerInfoProps {
    name: string;
    level: number;
    avatarUrl?: string; // Opcjonalny URL do avatara
    isMainPlayer?: boolean; // Czy to jest główny gracz
}

const props = defineProps<PlayerInfoProps>();

// Domyślny avatar, jeśli nie podano URL
const defaultAvatar = 'https://via.placeholder.com/40/000000/FFFFFF?text=P'; // Mniejszy placeholder
</script>

<template>
    <div :class="['player-info-container', { 'main-player': isMainPlayer }]">
        <div class="avatar-wrapper">
            <img :src="avatarUrl || defaultAvatar" alt="Avatar gracza" class="player-avatar" />
        </div>
        <div class="player-details">
            <h4 class="player-name">{{ name }}</h4>
            <span class="player-level">Poziom {{ level }}</span>
        </div>
    </div>
</template>

<style scoped>
.player-info-container {
    display: flex;
    align-items: center;
    gap: 10px;
    color: white;
    text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.7);
    font-family: 'Arial', sans-serif;
    padding: 5px 10px;
    border-radius: 20px;
    background-color: rgba(0, 0, 0, 0.4);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
}

.avatar-wrapper {
    position: relative;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid #facc15;
    box-shadow: 0 0 5px rgba(0, 0, 0, 0.3);
    flex-shrink: 0;
}

.player-avatar {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.player-details {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.player-name {
    font-size: 1rem;
    font-weight: bold;
    margin-bottom: 0px;
    color: #fde047;
    white-space: nowrap;
}

.player-level {
    background-color: #ef4444;
    color: white;
    padding: 1px 5px;
    border-radius: 9999px;
    font-size: 0.65rem;
    font-weight: bold;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

/* Styl dla głównego gracza */
.player-info-container.main-player {
    background-color: rgba(34, 197, 94, 0.3);
    border: 1px solid #22c55e;
}

.player-info-container.main-player .avatar-wrapper {
    border-color: #22c55e;
    box-shadow: 0 0 10px rgba(34, 197, 94, 0.6);
}

.player-info-container.main-player .player-name {
    color: #86efac;
    font-size: 1.2rem;
}
.player-info-container.main-player .player-level {
    background-color: #16a34a;
}
</style>
