      <div v-for="(player, index) in gameState.players" :key="player.user_id" class="flex items-center space-x-2 p-2 rounded-md" :class="{ 'bg-green-100': player.ready, 'bg-red-50': !player.ready }">
        <div class="w-8 h-8 flex items-center justify-center rounded-full bg-primary text-white font-bold">
          {{ index + 1 }}
        </div>
        <div class="flex-1">
          <!-- Wyświetl nazwę użytkownika, nigdy nie pokazuj 'Guest' -->
          {{ getDisplayName(player.username, player.user_id) }}
          <span v-if="player.user_id === user.id" class="text-xs text-gray-500">(Ty)</span>
        </div>
        <div class="flex items-center">
          <CheckCircle2 v-if="player.ready" class="h-5 w-5 text-green-500" />
          <XCircle v-else class="h-5 w-5 text-red-500" />
        </div>
      </div>
