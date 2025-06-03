import { usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

export interface User {
  id: number;
  name: string;
  ws_token: string;
}

export function useAuth() {
  const user = usePage()?.props?.auth?.user;
  const isLoggedIn = computed(() => !!user.value);

  const getAuthenticatedUser = () => {
    const authUser = usePage()?.props?.auth?.user;
    if (authUser) {
      user.value = {
        id: authUser.id,
        name: authUser.name,
        ws_token: authUser.ws_token
      };
    }
    return null;
  };

  return {
    user,
    isLoggedIn,
    getAuthenticatedUser,
  };
}
