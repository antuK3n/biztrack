import axios, { AxiosError, AxiosInstance } from 'axios';
import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'biztrack_token';

// Base URL is configurable. On-device users set EXPO_PUBLIC_API_URL to their LAN IP.
export const API_URL =
  process.env.EXPO_PUBLIC_API_URL || 'http://localhost:8080/api/v1';

let onUnauthorized: (() => void) | null = null;

/** Registered by AuthContext so a 401 anywhere triggers a logout. */
export function setUnauthorizedHandler(fn: (() => void) | null) {
  onUnauthorized = fn;
}

export async function getToken(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(TOKEN_KEY);
  } catch {
    return null;
  }
}

export async function setToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function clearToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

export const api: AxiosInstance = axios.create({
  baseURL: API_URL,
  headers: { Accept: 'application/json' },
  timeout: 20000,
});

// Attach the bearer token to every request.
api.interceptors.request.use(async (config) => {
  const token = await getToken();
  if (token) {
    config.headers = config.headers ?? {};
    (config.headers as Record<string, string>).Authorization = `Bearer ${token}`;
  }
  return config;
});

// 401 → clear token + notify the auth layer to log out.
api.interceptors.response.use(
  (res) => res,
  async (error: AxiosError) => {
    if (error.response?.status === 401) {
      await clearToken();
      onUnauthorized?.();
    }
    return Promise.reject(error);
  },
);

/** Extracts a human-readable message from a Laravel-shaped error. */
export function apiError(err: unknown): string {
  const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
  if (e?.response?.data) {
    const data = e.response.data;
    if (data.errors) {
      const first = Object.values(data.errors)[0];
      if (first?.length) return first[0];
    }
    if (data.message) return data.message;
  }
  if (e?.message === 'Network Error') {
    return 'Could not reach the server. Check your connection and API URL.';
  }
  return e?.message || 'Something went wrong. Please try again.';
}
