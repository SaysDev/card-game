import { ref, computed } from 'vue';

export interface User {
  id: number;
  name: string;
  email: string;
  avatar?: string;
}

export function useAuth() {
  // This is a simplified auth state management for demo purposes
  // In a real app, this would integrate with Laravel Sanctum/Fortify
  const user = ref<User | null>(null);
  const isLoggedIn = computed(() => !!user.value);

  // For demo purposes, set a mock user
  const setMockUser = () => {
    user.value = {
      id: 1,
      name: 'Gracz Główny (Ty)',
      email: 'player@example.com',
      avatar: 'https://picsum.photos/seed/1001/60/60'
    };
  };

  // Initialize mock user for demo
  setMockUser();

  const login = async (email: string, password: string) => {
    // Mock login process
    return new Promise<User>((resolve) => {
      setTimeout(() => {
        const mockUser = {
          id: 1,
          name: 'Gracz Główny (Ty)',
          email: email,
          avatar: 'https://picsum.photos/seed/1001/60/60'
        };
        user.value = mockUser;
        resolve(mockUser);
      }, 500);
    });
  };

  const logout = async () => {
    // Mock logout process
    return new Promise<void>((resolve) => {
      setTimeout(() => {
        user.value = null;
        resolve();
      }, 500);
    });
  };

  return {
    user,
    isLoggedIn,
    login,
    logout,
    setMockUser
  };
}
