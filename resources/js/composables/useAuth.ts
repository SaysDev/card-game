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

  // This is a stub method that will be replaced with actual auth data from the backend
  // In a Laravel app with Inertia, auth data is passed through page props
  const setUserFromAuth = () => {
    // For now, we'll just check if user.value is already set
    // This will be populated from the props passed to your page components
    if (user.value && user.value.id) {
      console.log('User is already authenticated:', user.value.id);
      return true;
    }
    return false;
  };

  // Set user from auth data
  const setMockUser = () => {
    // First try to get the real authenticated user
    if (setUserFromAuth()) {
      console.log('Using authenticated user from setUserFromAuth, not setting mock user');
      return; // We have a real user, don't set a mock one
    }

    // Try to get user ID from route params or URL if all else fails
    try {
      // Check if we have a user ID in the URL or route params
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('user_id')) {
        const userId = parseInt(urlParams.get('user_id') || '', 10);
        if (!isNaN(userId)) {
          user.value = {
            id: userId,
            name: `User ${userId}`,
            email: `user${userId}@example.com`,
            avatar: `https://picsum.photos/seed/${userId}/60/60`
          };
          console.log(`Using user ID from URL: ${userId}`);
          return;
        }
      }
    } catch (error) {
      console.error('Error extracting user ID from URL:', error);
    }

    // Try to get user ID from route params or URL
    let defaultUserId;
    try {
      // Check if we have a user ID in the URL or route params
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('user_id')) {
        defaultUserId = parseInt(urlParams.get('user_id') || '', 10);
      }

      // If still no user ID, use a reasonable default only for development
      if (!defaultUserId || isNaN(defaultUserId)) {
        // In production, we should avoid setting mock users
        if (process.env.NODE_ENV === 'development') {
          defaultUserId = 1; // Default for development only
        } else {
          // In production, don't set a default - return null user
          console.warn('No valid user ID found and not in development mode');
          return;
        }
      }
    } catch (error) {
      console.error('Error determining default user ID:', error);
      return; // Don't set a user if we can't determine the ID
    }

    user.value = {
      id: defaultUserId,
      name: `Gracz ${defaultUserId}`,
      email: `player${defaultUserId}@example.com`,
      avatar: `https://picsum.photos/seed/${defaultUserId}/60/60`
    };

    console.log(`Set default user ID: ${defaultUserId}`);
  };

  // Initialize user from auth or set mock user for demo
  setMockUser();

  // Get the currently authenticated user from Laravel
  const getAuthenticatedUser = () => {
    try {
      // First try Inertia shared auth data
      // @ts-ignore - Inertia may not be defined
      if (window.Inertia && window.Inertia.shared.auth && window.Inertia.shared.auth.user) {
        // @ts-ignore
        return window.Inertia.shared.auth.user;
      }

      // Then try Laravel's auth data
      // @ts-ignore - Laravel global may not be defined
      if (window.Laravel && window.Laravel.user) {
        // @ts-ignore
        return window.Laravel.user;
      }
    } catch (error) {
      console.error('Error accessing auth data:', error);
    }
    return null;
  };

  const login = async (email: string, password: string) => {
    // Try to get the real authenticated user first
    const authUser = getAuthenticatedUser();
    if (authUser) {
      user.value = {
        id: authUser.id,
        name: authUser.name,
        email: authUser.email,
        avatar: authUser.avatar || `https://picsum.photos/seed/${authUser.id}/60/60`
      };
      console.log(`Using authenticated user with ID: ${authUser.id}`);
      return Promise.resolve(user.value);
    }

    // If no authenticated user, fall back to a basic demo implementation
    return new Promise<User>((resolve) => {
      setTimeout(() => {
        // For demo purposes, use a fixed ID when not authenticated
        const demoUserId = 1;
        const mockUser = {
          id: demoUserId,
          name: 'Demo User',
          email: email,
          avatar: `https://picsum.photos/seed/${demoUserId}/60/60`
        };

        user.value = mockUser;
        console.log(`Using demo user with ID: ${demoUserId}`);
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

  // Method to get the current user ID consistently from any source
  const getUserId = (): number => {
    // First try to get from current user value
    if (user.value && user.value.id) {
      console.log('Using user ID from user.value:', user.value.id);
      return user.value.id;
    }

    // Then try Laravel global auth
    try {
      // @ts-ignore - Laravel global may not be defined
      if (window.Laravel && window.Laravel.user) {
        // @ts-ignore
        const laravelUserId = window.Laravel.user.id;
        console.log('Using user ID from Laravel global:', laravelUserId);
        return laravelUserId;
      }
    } catch (e) { /* Continue if not available */ }

    // Try to get user ID from URL parameters before falling back to default
    try {
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('user_id')) {
        const urlUserId = parseInt(urlParams.get('user_id') || '', 10);
        if (!isNaN(urlUserId)) {
          console.log('Using user ID from URL parameters:', urlUserId);
          return urlUserId;
        }
      }

      // Try to extract user ID from URL path if it matches a pattern like /users/2 or /players/2
      const userIdMatch = window.location.pathname.match(/\/(users|players|profiles)\/([0-9]+)/);
      if (userIdMatch && userIdMatch[2]) {
        const pathUserId = parseInt(userIdMatch[2], 10);
        if (!isNaN(pathUserId)) {
          console.log('Using user ID from URL path:', pathUserId);
          return pathUserId;
        }
      }
    } catch (e) {
      console.error('Error extracting user ID from URL:', e);
    }

    // Fallback to default user ID - in development use 2 as default instead of 1
    // to avoid user ID conflicts with common user ID 1
    if (process.env.NODE_ENV === 'development') {
      console.log('No authenticated user found, using development default ID: 2');
      return 2;
    }

    console.log('No authenticated user found, using default ID: 1');
    return 1;
  };

  return {
    user,
    isLoggedIn,
    login,
    logout,
    setMockUser,
    getUserId
  };
}
