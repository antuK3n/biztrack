import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider } from './src/context/AuthContext';
import { NotificationsProvider } from './src/context/NotificationsContext';
import { RootNavigator } from './src/RootNavigator';

export default function App() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <NotificationsProvider>
          <StatusBar style="light" />
          <RootNavigator />
        </NotificationsProvider>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
