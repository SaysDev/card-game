import { useEffect, useCallback, useRef } from 'react';
import { WebSocketService } from '../services/WebSocketService';

export function useWebSocket() {
    const wsService = useRef(null);
    const [isConnected, setIsConnected] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        wsService.current = new WebSocketService();
        
        const connect = async () => {
            try {
                await wsService.current.connect();
                setIsConnected(true);
                setError(null);
            } catch (err) {
                setError(err.message);
                setIsConnected(false);
            }
        };

        connect();

        return () => {
            if (wsService.current) {
                wsService.current.disconnect();
            }
        };
    }, []);

    const send = useCallback((data) => {
        if (wsService.current) {
            wsService.current.send(data);
        }
    }, []);

    const on = useCallback((type, callback) => {
        if (wsService.current) {
            wsService.current.on(type, callback);
        }
    }, []);

    const off = useCallback((type) => {
        if (wsService.current) {
            wsService.current.off(type);
        }
    }, []);

    const joinMatchmaking = useCallback(() => {
        if (wsService.current) {
            wsService.current.joinMatchmaking();
        }
    }, []);

    return {
        isConnected,
        error,
        send,
        on,
        off,
        joinMatchmaking
    };
} 