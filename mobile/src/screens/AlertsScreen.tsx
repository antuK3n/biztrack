import React from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Card } from '../components/Card';
import { EmptyView, ErrorView, LoadingView } from '../components/StateViews';
import { useNotifications } from '../context/NotificationsContext';
import { dateTime } from '../format';
import { colors, spacing } from '../theme';
import type { Notification } from '../types';

export function AlertsScreen() {
  const { items, loading, error, refresh, markRead } = useNotifications();

  const renderItem = ({ item }: { item: Notification }) => {
    const unread = !item.read_at;
    return (
      <Card
        style={[styles.card, unread && styles.cardUnread]}
        onPress={() => (unread ? markRead(item.id) : undefined)}
      >
        <View style={styles.headerRow}>
          {unread ? <View style={styles.unreadDot} /> : <View style={styles.dotSpacer} />}
          <Text style={[styles.title, unread && styles.titleUnread]} numberOfLines={2}>
            {item.title}
          </Text>
        </View>
        {item.body ? <Text style={styles.body}>{item.body}</Text> : null}
        <Text style={styles.time}>{dateTime(item.created_at)}</Text>
      </Card>
    );
  };

  if (loading && items.length === 0) return <LoadingView label="Loading alerts…" />;
  if (error && items.length === 0) return <ErrorView message={error} onRetry={refresh} />;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Text style={styles.pageTitle}>Alerts</Text>
      <FlatList
        data={items}
        keyExtractor={(it) => String(it.id)}
        renderItem={renderItem}
        contentContainerStyle={styles.list}
        ItemSeparatorComponent={() => <View style={{ height: spacing.md }} />}
        refreshControl={
          <RefreshControl refreshing={loading} onRefresh={refresh} tintColor={colors.royal} />
        }
        ListEmptyComponent={
          <EmptyView
            icon="🔔"
            title="You're all caught up"
            subtitle="Status changes and reminders will show up here."
          />
        }
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  pageTitle: {
    fontSize: 24,
    fontWeight: '800',
    color: colors.text,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
    paddingBottom: spacing.md,
  },
  list: { padding: spacing.xl, paddingTop: spacing.xs, flexGrow: 1 },
  card: {},
  cardUnread: {
    borderLeftWidth: 4,
    borderLeftColor: colors.royal,
  },
  headerRow: { flexDirection: 'row', alignItems: 'center' },
  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 999,
    backgroundColor: colors.royal,
    marginRight: spacing.sm,
  },
  dotSpacer: { width: 8, marginRight: spacing.sm },
  title: { flex: 1, fontSize: 15, fontWeight: '600', color: colors.text },
  titleUnread: { fontWeight: '800' },
  body: { color: colors.textMuted, fontSize: 14, marginTop: spacing.sm, lineHeight: 20 },
  time: { color: colors.textMuted, fontSize: 12, marginTop: spacing.sm },
});
