import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import {
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api, apiError } from '../api';
import { Card } from '../components/Card';
import { EmptyView, ErrorView, LoadingView } from '../components/StateViews';
import { StatusChip } from '../components/StatusChip';
import type { TrackStackParamList } from '../navigation';
import { colors, radius, spacing } from '../theme';
import type { ApplicationListItem } from '../types';
import { humanize } from '../theme';

type Props = NativeStackScreenProps<TrackStackParamList, 'TrackList'>;

const FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'new', label: 'New Permit' },
  { key: 'renewal', label: 'Renewal' },
  { key: 'amendment', label: 'Amendment' },
] as const;

export function TrackListScreen({ route, navigation }: Props) {
  const note = route.params?.note;
  const [items, setItems] = useState<ApplicationListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<string>('all');

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api.get<{ data: ApplicationListItem[] }>('/applications');
      setItems(res.data.data ?? []);
    } catch (e) {
      setError(apiError(e));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const onRefresh = () => {
    setRefreshing(true);
    load();
  };

  const visible = items.filter((it) => {
    if (filter === 'all') return true;
    const t = (it.application_type || '').toLowerCase();
    if (filter === 'new') return t.includes('new');
    return t.includes(filter);
  });

  const renderItem = ({ item }: { item: ApplicationListItem }) => (
    <Card
      style={styles.row}
      onPress={() =>
        navigation.navigate('ApplicationDetail', {
          id: item.id,
          trackingId: item.tracking_id,
        })
      }
    >
      <View style={styles.rowMain}>
        <Text style={styles.tracking}>{item.tracking_id}</Text>
        <Text style={styles.business} numberOfLines={1}>
          {item.business?.name ?? 'Business'}
        </Text>
        <Text style={styles.type}>{humanize(item.application_type)}</Text>
      </View>
      <StatusChip status={item.status} label={item.status_label} />
    </Card>
  );

  if (loading) return <LoadingView label="Loading applications…" />;
  if (error) return <ErrorView message={error} onRetry={load} />;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Text style={styles.title}>Permit Tracking</Text>

      {note ? (
        <View style={styles.note}>
          <Text style={styles.noteText}>ℹ️ {note}</Text>
        </View>
      ) : null}

      <View style={styles.filters}>
        {FILTERS.map((f) => {
          const active = filter === f.key;
          return (
            <Pressable
              key={f.key}
              onPress={() => setFilter(f.key)}
              style={[styles.filterChip, active && styles.filterActive]}
            >
              <Text style={[styles.filterText, active && styles.filterTextActive]}>
                {f.label}
              </Text>
            </Pressable>
          );
        })}
      </View>

      <FlatList
        data={visible}
        keyExtractor={(it) => String(it.id)}
        renderItem={renderItem}
        contentContainerStyle={styles.list}
        ItemSeparatorComponent={() => <View style={{ height: spacing.md }} />}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.royal} />
        }
        ListEmptyComponent={
          <EmptyView
            icon="🗂️"
            title="No applications yet"
            subtitle="Applications you file on the web portal will appear here."
          />
        }
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  title: {
    fontSize: 24,
    fontWeight: '800',
    color: colors.text,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
  },
  note: {
    marginHorizontal: spacing.xl,
    marginTop: spacing.md,
    backgroundColor: colors.input,
    borderRadius: radius.input,
    padding: spacing.md,
  },
  noteText: { color: colors.royalDark, fontSize: 13, lineHeight: 19 },
  filters: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.md,
  },
  filterChip: {
    borderRadius: radius.pill,
    borderWidth: 1.5,
    borderColor: colors.royal,
    paddingVertical: 6,
    paddingHorizontal: 14,
  },
  filterActive: { backgroundColor: colors.royal },
  filterText: { color: colors.royal, fontWeight: '700', fontSize: 13 },
  filterTextActive: { color: colors.white },
  list: { padding: spacing.xl, paddingTop: spacing.xs, flexGrow: 1 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  rowMain: { flex: 1, marginRight: spacing.md },
  tracking: { color: colors.textMuted, fontSize: 12, fontWeight: '700' },
  business: { color: colors.text, fontSize: 17, fontWeight: '700', marginTop: 2 },
  type: { color: colors.textMuted, fontSize: 13, marginTop: 2 },
});
