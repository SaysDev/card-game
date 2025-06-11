        <div v-for="player in gameState.players" :key="player.user_id"
          class="absolute player-position flex flex-col items-center"
          :class="[getPlayerPosition(player),
                  { 'current-player': player.user_id === gameState.currentPlayerId },
                  { 'current-user': player.user_id === user.id }]">
          <div class="player-avatar rounded-full bg-primary w-12 h-12 flex items-center justify-center mb-1">
            <UserIcon v-if="!player.avatar" class="w-6 h-6 text-white" />
            <img v-else :src="player.avatar" class="w-full h-full rounded-full" />
          </div>
          <div class="player-name text-sm font-medium bg-white/90 rounded px-2 py-0.5 shadow-sm">
            {{ getDisplayName(player.username, player.user_id) }}
            <span v-if="player.user_id === user.id" class="text-xs text-gray-500">(Ty)</span>
          </div>
