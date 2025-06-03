    // Use inertiaUser from usePage() directly
    const userName = inertiaUser.value ? inertiaUser.value.name : 'Guest';

    // Pobierz token z localStorage lub użyj domyślnej wartości
    const wsToken = localStorage.getItem('ws_auth_token') || 'token';
    console.log('WebSocket token:', wsToken);

    // Connect using the inertiaUser or authenticatedUserId if already determined
    if (inertiaUser.value?.id) {
      console.log('Connecting to WebSocket with user ID from Inertia:', inertiaUser.value.id);
      WebSocketService.connect(String(inertiaUser.value.id), userName, wsToken);
    } else if (authenticatedUserId) {
      console.log('Connecting to WebSocket with fallback user ID:', authenticatedUserId);
      WebSocketService.connect(String(authenticatedUserId), userName, wsToken);
    } else {
      console.error('Cannot connect to WebSocket: No valid user ID available');
      toast({
        title: 'Błąd połączenia',
        description: 'Nie można połączyć się z serwerem - brak identyfikatora użytkownika',
        variant: 'destructive',
      });
    }
