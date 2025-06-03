<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
  name: '',
  max_players: 4, // Default value
});

const processing = ref(false);

const submit = () => {
  processing.value = true;
  form.post(route('games.store'), {
    onFinish: () => {
      processing.value = false;
    },
  });
};
</script>

<template>
  <Head title="Utwórz nową grę" />

  <div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
      <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
        <header>
          <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Utwórz nową grę</h2>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Wypełnij poniższy formularz, aby utworzyć nową grę karcianą.
          </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nazwa gry</label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
              required
              autofocus
            />
            <div v-if="form.errors.name" class="text-red-500 text-sm mt-1">{{ form.errors.name }}</div>
          </div>

          <div>
            <label for="max_players" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Maksymalna liczba graczy</label>
            <select
              id="max_players"
              v-model="form.max_players"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
              required
            >
              <option value="2">2 graczy</option>
              <option value="3">3 graczy</option>
              <option value="4">4 graczy</option>
              <option value="5">5 graczy</option>
              <option value="6">6 graczy</option>
              <option value="7">7 graczy</option>
              <option value="8">8 graczy</option>
            </select>
            <div v-if="form.errors.max_players" class="text-red-500 text-sm mt-1">{{ form.errors.max_players }}</div>
          </div>

          <div class="flex items-center gap-4">
            <button
              :disabled="processing"
              type="submit"
              class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
              Utwórz grę
            </button>
            <a
              :href="route('games.index')"
              class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
              Anuluj
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
