import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { DefaultTheme, NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import React from 'react';
import { View } from 'react-native';
import { SignOutButton } from './components/SignOutButton';
import { LoadingView } from './components/StateViews';
import { TabBarIcon } from './components/TabBarIcon';
import { useAuth } from './context/AuthContext';
import { useNotifications } from './context/NotificationsContext';
import type {
  PermitsStackParamList,
  TabParamList,
  TrackStackParamList,
} from './navigation';
import { AlertsScreen } from './screens/AlertsScreen';
import { ApplicationDetailScreen } from './screens/ApplicationDetailScreen';
import { HomeScreen } from './screens/HomeScreen';
import { LoginScreen } from './screens/LoginScreen';
import { PermitDetailScreen } from './screens/PermitDetailScreen';
import { PermitsListScreen } from './screens/PermitsListScreen';
import { RequestsScreen } from './screens/RequestsScreen';
import { TrackListScreen } from './screens/TrackListScreen';
import { colors } from './theme';

const Tab = createBottomTabNavigator<TabParamList>();
const TrackStack = createNativeStackNavigator<TrackStackParamList>();
const PermitsStack = createNativeStackNavigator<PermitsStackParamList>();

// Royal header styling shared across stacks.
const stackScreenOptions = {
  headerStyle: { backgroundColor: colors.royal },
  headerTintColor: colors.white,
  headerTitleStyle: { fontWeight: '700' as const },
  headerRight: () => <SignOutButton />,
  contentStyle: { backgroundColor: colors.canvas },
};

function TrackNavigator() {
  return (
    <TrackStack.Navigator screenOptions={stackScreenOptions}>
      <TrackStack.Screen
        name="TrackList"
        component={TrackListScreen}
        options={{ headerShown: false }}
      />
      <TrackStack.Screen
        name="ApplicationDetail"
        component={ApplicationDetailScreen}
        options={{ title: 'Application' }}
      />
      <TrackStack.Screen
        name="Requests"
        component={RequestsScreen}
        options={{ headerShown: false }}
      />
    </TrackStack.Navigator>
  );
}

function PermitsNavigator() {
  return (
    <PermitsStack.Navigator screenOptions={stackScreenOptions}>
      <PermitsStack.Screen
        name="PermitsList"
        component={PermitsListScreen}
        options={{ headerShown: false }}
      />
      <PermitsStack.Screen
        name="PermitDetail"
        component={PermitDetailScreen}
        options={{ title: 'Permit' }}
      />
    </PermitsStack.Navigator>
  );
}

function Tabs() {
  const { unread } = useNotifications();
  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        headerStyle: { backgroundColor: colors.royal },
        headerTintColor: colors.white,
        headerTitleStyle: { fontWeight: '700' },
        headerRight: () => <SignOutButton />,
        headerShown: route.name === 'Home',
        tabBarActiveTintColor: colors.white,
        tabBarInactiveTintColor: 'rgba(255,255,255,0.7)',
        tabBarStyle: {
          backgroundColor: colors.royal,
          borderTopWidth: 0,
          height: 62,
          paddingTop: 6,
          paddingBottom: 8,
        },
        tabBarLabelStyle: { fontSize: 11, fontWeight: '700' },
        tabBarIcon: ({ focused }) => (
          <TabBarIcon
            name={route.name}
            focused={focused}
            badge={route.name === 'Alerts' ? unread : undefined}
          />
        ),
      })}
    >
      <Tab.Screen name="Home" component={HomeScreen} options={{ title: 'BizTrack' }} />
      <Tab.Screen name="Track" component={TrackNavigator} />
      <Tab.Screen name="Permits" component={PermitsNavigator} />
      <Tab.Screen
        name="Alerts"
        component={AlertsScreen}
        options={{ tabBarBadge: undefined }}
      />
    </Tab.Navigator>
  );
}

const navTheme = {
  ...DefaultTheme,
  colors: { ...DefaultTheme.colors, background: colors.canvas, primary: colors.royal },
};

export function RootNavigator() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.canvas }}>
        <LoadingView label="Starting BizTrack…" />
      </View>
    );
  }

  return (
    <NavigationContainer theme={navTheme}>
      {user ? <Tabs /> : <LoginScreen />}
    </NavigationContainer>
  );
}
