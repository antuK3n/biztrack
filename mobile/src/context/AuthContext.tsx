import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api, clearToken, getToken, setToken, setUnauthorizedHandler } from '../api';
import { LoginResponse, User } from '../types';

interface AuthState {
  user: User | null;
  loading: boolean; // initial token restore
  signIn: (identifier: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthState | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const signOut = useCallback(async () => {
    await clearToken();
    setUser(null);
  }, []);

  // Wire up the 401 → logout handler once.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      setUser(null);
    });
    return () => setUnauthorizedHandler(null);
  }, []);

  // Restore a persisted session on launch.
  useEffect(() => {
    (async () => {
      const token = await getToken();
      if (!token) {
        setLoading(false);
        return;
      }
      try {
        const res = await api.get<{ data: User }>('/auth/me');
        setUser(res.data.data);
      } catch {
        // token invalid/expired — the 401 interceptor clears it.
        await clearToken();
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const signIn = useCallback(async (identifier: string, password: string) => {
    const res = await api.post<{ data: LoginResponse }>('/auth/login', {
      email: identifier,
      password,
    });
    const { token, user: u } = res.data.data;
    await setToken(token);
    setUser(u);
  }, []);

  return (
    <AuthContext.Provider value={{ user, loading, signIn, signOut }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
