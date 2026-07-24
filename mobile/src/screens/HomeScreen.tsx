import { useNavigation } from '@react-navigation/native';
import type { BottomTabNavigationProp } from '@react-navigation/bottom-tabs';
import React from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Card } from '../components/Card';
import { Wordmark } from '../components/Wordmark';
import { useAuth } from '../context/AuthContext';
import type { TabParamList } from '../navigation';
import { colors, spacing } from '../theme';

const WEB_NOTE = 'File on the BizTrack web portal for now. Track progress here.';

interface Action {
  icon: string;
  title: string;
  onPress: (nav: BottomTabNavigationProp<TabParamList>) => void;
}

const ACTIONS: Action[] = [
  {
    icon: '📋',
    title: 'New Business Permit',
    onPress: (nav) => nav.navigate('Track', { screen: 'TrackList', params: { note: WEB_NOTE } }),
  },
  {
    icon: '🔄',
    title: 'Renew Business Permit',
    onPress: (nav) => nav.navigate('Track', { screen: 'TrackList', params: { note: WEB_NOTE } }),
  },
  {
    icon: '📝',
    title: 'Amendment Form',
    onPress: (nav) => nav.navigate('Track', { screen: 'TrackList', params: { note: WEB_NOTE } }),
  },
  {
    icon: '🛡️',
    title: 'Other Requirements',
    onPress: (nav) => nav.navigate('Track', { screen: 'Requests' }),
  },
];

export function HomeScreen() {
  const navigation = useNavigation<BottomTabNavigationProp<TabParamList>>();
  const { user } = useAuth();

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <ScrollView contentContainerStyle={styles.scroll}>
        {user?.name ? (
          <Text style={styles.greeting}>Hello, {user.name.split(' ')[0]} 👋</Text>
        ) : null}
        <Text style={styles.heading}>Track your businesses with</Text>
        <View style={styles.wordmarkWrap}>
          <Wordmark size={40} />
        </View>

        <View style={styles.grid}>
          {ACTIONS.map((a) => (
            <Card
              key={a.title}
              style={styles.actionCard}
              onPress={() => a.onPress(navigation)}
            >
              <Text style={styles.actionIcon}>{a.icon}</Text>
              <Text style={styles.actionTitle}>{a.title}</Text>
            </Card>
          ))}
        </View>

        <Text style={styles.footNote}>
          New filings, renewals and amendments are submitted on the BizTrack web
          portal. Use this app to track status, pay fees, and manage requirements.
        </Text>
      </ScrollView>
    </SafeAreaView>
  );
}

const CARD_GAP = spacing.md;

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  scroll: { padding: spacing.xl, paddingBottom: spacing.xl * 2 },
  greeting: {
    color: colors.textMuted,
    fontSize: 15,
    marginBottom: spacing.xs,
  },
  heading: {
    color: colors.text,
    fontSize: 24,
    fontWeight: '700',
    textAlign: 'center',
    marginTop: spacing.sm,
  },
  wordmarkWrap: {
    alignItems: 'center',
    marginTop: spacing.xs,
    marginBottom: spacing.xl,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
  },
  actionCard: {
    width: '48%',
    marginBottom: CARD_GAP,
    alignItems: 'center',
    paddingVertical: spacing.xl,
    minHeight: 140,
    justifyContent: 'center',
  },
  actionIcon: { fontSize: 40, marginBottom: spacing.md },
  actionTitle: {
    color: colors.royal,
    fontWeight: '700',
    fontSize: 15,
    textAlign: 'center',
  },
  footNote: {
    color: colors.textMuted,
    fontSize: 13,
    lineHeight: 20,
    textAlign: 'center',
    marginTop: spacing.lg,
  },
});
