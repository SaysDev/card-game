<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { useWebSocket } from '@/composables/useWebSocket';
import { useToast } from '@/components/ui/toast';

defineProps<{
  games: Array<{
    id: number;
    name: string;
    status: string;
    max_players: number;
    current_players: number;
    created_at: string;
  }>;
}>();

const { toast } = useToast();
const { isConnected, connect, listRooms } = useWebSocket();
const activeRooms = ref([]);

onMounted(() => {
  connect();
});

const refreshRooms = () => {
  if (isConnected.value) {
    listRooms();
    toast({
      title: 'Odświeżanie',
      description: 'Lista pokojów została odświeżona',
    });
  } else {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można połączyć z serwerem gry',
      variant: 'destructive',
    });
  }
};
</script>

<template>
  <Head title="Lista gier" />

  <div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
      <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Dostępne Gry</h2>
          <div class="flex items-center space-x-4">
              <div v-if="$page.props.auth.user.activeGame" class="mb-4 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700">
                  <div class="flex">
                      <div class="flex-shrink-0">
                          <svg class="h-5 w-5 text-yellow-500" viewBox="0 0 20 20" fill="currentColor">
                              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                          </svg>
                      </div>
                      <div class="ml-3">
                          <p class="text-sm font-bold">Jesteś już w grze</p>
                          <p class="text-sm">Musisz opuścić obecną grę zanim dołączysz do nowej.</p>
                          <div class="mt-2">
                              <div class="text-sm mb-1">
                                  <span class="font-medium">Gra:</span>
                                  <span class="font-bold">{{ $page.props.auth.user.activeGame.name }}</span>
                              </div>
                              <div class="text-sm mb-2">
                                  <span class="font-medium">Status:</span>
                                  <span class="px-2 py-0.5 rounded text-xs font-medium"
                                        :class="{
                                          'bg-yellow-200 text-yellow-800': $page.props.auth.user.activeGame.status === 'waiting',
                                          'bg-green-200 text-green-800': $page.props.auth.user.activeGame.status === 'playing',
                                          'bg-gray-200 text-gray-800': $page.props.auth.user.activeGame.status === 'ended'
                                        }">
                                      {{ $page.props.auth.user.activeGame.status === 'waiting' ? 'Oczekiwanie' :
                                         $page.props.auth.user.activeGame.status === 'playing' ? 'W trakcie' : 'Zakończona' }}
                                  </span>
                              </div>
                              <Link
                                  :href="route('games.show', $page.props.auth.user.activeGame.id)"
                                  class="inline-flex items-center px-3 py-1.5 bg-yellow-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-500 active:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 transition ease-in-out duration-150"
                              >
                                  Przejdź do gry
                              </Link>
                              <Link
                                  :href="route('games.leave', $page.props.auth.user.activeGame.id)"
                                  method="post"
                                  as="button"
                                  class="inline-flex items-center ml-2 px-3 py-1.5 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                              >
                                  Opuść grę
                              </Link>
                          </div>
                      </div>
                  </div>
              </div>
            <Link
              :href="route('games.create')"
              class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
              Utwórz nową grę
            </Link>
            <button
              @click="refreshRooms"
              class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
              Odśwież
            </button>
          </div>
        </div>

        <div class="overflow-x-auto relative">
          <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
              <tr>
                <th scope="col" class="py-3 px-6">Nazwa gry</th>
                <th scope="col" class="py-3 px-6">Status</th>
                <th scope="col" class="py-3 px-6">Gracze</th>
                <th scope="col" class="py-3 px-6">Utworzono</th>
                <th scope="col" class="py-3 px-6">Akcje</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="games.length === 0" class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                <td colspan="5" class="py-6 px-6 text-center">Brak dostępnych gier. Utwórz nową lub odśwież listę.</td>
              </tr>
              <tr
                v-for="game in games"
                :key="game.id"
                class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700"
              >
                <td class="py-4 px-6 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                  {{ game.name }}
                </td>
                <td class="py-4 px-6">
                  <span
                    :class="{
                      'px-2 py-1 rounded text-xs font-semibold': true,
                      'bg-yellow-100 text-yellow-800': game.status === 'waiting',
                      'bg-green-100 text-green-800': game.status === 'in_progress',
                      'bg-red-100 text-red-800': game.status === 'ended'
                    }"
                  >
                    {{ game.status === 'waiting' ? 'Oczekuje' :
                       game.status === 'in_progress' ? 'W trakcie' : 'Zakończona' }}
                  </span>
                </td>
                <td class="py-4 px-6">
                  {{ game.current_players }} / {{ game.max_players }}
                </td>
                <td class="py-4 px-6">
                  {{ new Date(game.created_at).toLocaleString() }}
                </td>
                <td class="py-4 px-6">
                  <Link
                    :href="route('games.show', game.id)"
                    class="font-medium text-blue-600 dark:text-blue-500 hover:underline mr-3"
                  >
                    Pokaż
                  </Link>
                  <Link
                    v-if="game.status === 'waiting' && game.current_players < game.max_players"
                    :href="route('games.join', game.id)"
                    method="post"
                    as="button"
                    class="font-medium text-green-600 dark:text-green-500 hover:underline"
                  >
                    Dołącz
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
