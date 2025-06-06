<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import WebSocketService from '@/Services/WebSocketService';
import { onMounted } from 'vue';

onMounted(() => {
  if (!WebSocketService.isConnectedAndReady()) {
    WebSocketService.connect();
  }

  
});

function runPerformanceDemo() {
  WebSocketService.startPerformanceDemo(100, 4);
}
</script>

<template>
  <Head title="Dashboard" />

  <div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
      <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
          <h1 class="text-2xl font-semibold mb-6">Panel Gracza</h1>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="p-6 bg-gray-100 dark:bg-gray-700 rounded-lg shadow-md hover:shadow-lg transition-all">
              <h2 class="text-xl font-medium mb-4">Moje Gry</h2>
              <p class="text-gray-600 dark:text-gray-400 mb-4">Przeglądaj dostępne gry, dołącz do istniejących lub stwórz własną.</p>
              <Link
                :href="route('games.index')"
                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Przejdź do gier
              </Link>
            </div>

            <div class="p-6 bg-gray-100 dark:bg-gray-700 rounded-lg shadow-md hover:shadow-lg transition-all">
              <h2 class="text-xl font-medium mb-4">Mój Profil</h2>
              <p class="text-gray-600 dark:text-gray-400 mb-4">Zarządzaj swoim profilem, zmień hasło lub aktualizuj dane osobowe.</p>
              <Link
                :href="route('profile.edit')"
                class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 focus:bg-green-700 active:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Edytuj profil
              </Link>
            </div>

            <div class="p-6 bg-gray-100 dark:bg-gray-700 rounded-lg shadow-md hover:shadow-lg transition-all">
              <h2 class="text-xl font-medium mb-4">Statystyki</h2>
              <p class="text-gray-600 dark:text-gray-400 mb-4">Sprawdź swoje statystyki, osiągnięcia i historię rozgrywek.</p>
              <button
                class="inline-flex items-center px-4 py-2 bg-purple-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-purple-700 focus:bg-purple-700 active:bg-purple-800 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition ease-in-out duration-150"
                disabled
              >
                Wkrótce dostępne
              </button>
            </div>
          </div>

          <div class="mt-8 p-4 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg border border-yellow-200 dark:border-yellow-800">
            <h3 class="text-lg font-medium text-yellow-800 dark:text-yellow-200 mb-2">Nowości w grze!</h3>
            <p class="text-yellow-700 dark:text-yellow-300">
              Dodaliśmy nową funkcję rozgrywki przez WebSocket, która umożliwia grę w czasie rzeczywistym z innymi graczami.
              Wypróbuj nową funkcję już teraz, tworząc lub dołączając do gry!
            </p>
          </div>

          <div class="mt-8 flex justify-center">
            <button
              @click="runPerformanceDemo"
              class="inline-flex items-center px-6 py-3 bg-red-600 border border-transparent rounded-md font-semibold text-base text-white uppercase tracking-widest hover:bg-red-700 focus:bg-red-700 active:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150 shadow-lg"
            >
              Uruchom performance demo (100 pokoi x 4 boty)
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

