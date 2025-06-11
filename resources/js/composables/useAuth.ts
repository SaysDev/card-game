import { usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

export interface User {
  id: number;
  name: string;
  ws_token: string;
}

interface PageProps {
  auth: {
    user: User;
  };
  [key: string]: any;
}

export function useAuth() {
  const page = usePage<PageProps>();
  const user = ref<User | null>(page.props.auth?.user || null);
  const isLoggedIn = computed(() => !!user.value);

  const getAuthenticatedUser = () => {
    const authUser = page.props.auth?.user;
    if (authUser) {
      user.value = {
        id: authUser.id,
        name: authUser.name,
        ws_token: authUser.ws_token
      };
      return user.value;
    }
    return null;
  };

  // Initialize user state
  getAuthenticatedUser();

  return {
    user,
    isLoggedIn,
    getAuthenticatedUser,
  };
}
