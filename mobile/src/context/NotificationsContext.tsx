import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from 'react';
import { api } from '../api';
import { useAuth } from './AuthContext';
import type { Notification } from '../types';

interface NotifState {
  items: Notification[];
  unread: number;
  loading: boolean;
  error: string | null;
  refresh: () => Promise<void>;
  markRead: (id: number) => Promise<void>;
}

const Ctx = createContext<NotifState | undefined>(undefined);

export function NotificationsProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const [items, setItems] = useState<Notification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    if (!user) return;
    setError(null);
    setLoading(true);
    try {
      const res = await api.get<{ data: Notification[]; meta?: { unread: number } }>(
        '/notifications',
      );
      const data = res.data.data ?? [];
      setItems(data);
      setUnread(
        res.data.meta?.unread ?? data.filter((n) => !n.read_at).length,
      );
    } catch (e) {
      setError('Could not load alerts.');
    } finally {
      setLoading(false);
    }
  }, [user]);

  const markRead = useCallback(async (id: number) => {
    // Optimistic update.
    setItems((prev) =>
      prev.map((n) =>
        n.id === id && !n.read_at ? { ...n, read_at: new Date().toISOString() } : n,
      ),
    );
    setUnread((u) => Math.max(0, u - 1));
    try {
      await api.post(`/notifications/${id}/read`);
    } catch {
      // On failure, re-sync from server.
      refresh();
    }
  }, [refresh]);

  // Load + light polling while signed in (every 45s).
  useEffect(() => {
    if (!user) {
      setItems([]);
      setUnread(0);
      return;
    }
    refresh();
    const t = setInterval(refresh, 45000);
    return () => clearInterval(t);
  }, [user, refresh]);

  return (
    <Ctx.Provider value={{ items, unread, loading, error, refresh, markRead }}>
      {children}
    </Ctx.Provider>
  );
}

export function useNotifications(): NotifState {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error('useNotifications must be used within NotificationsProvider');
  return ctx;
}
