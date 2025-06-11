                <span class="text-xs text-gray-600">
                  {{ room.player_count }} / {{ room.max_players }} graczy
                  <span v-if="room.creator" class="ml-1">
                    • Twórca: {{ getDisplayName(room.creator, room.creator_id) }}
                  </span>
                </span>
