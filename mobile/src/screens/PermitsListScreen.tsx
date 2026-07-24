import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api, apiError } from '../api';
import { Card } from '../components/Card';
import { EmptyView, ErrorView, LoadingView } from '../components/StateViews';
import { StatusChip } from '../components/StatusChip';
import { shortDate } from '../format';
import type { PermitsStackParamList } from '../navigation';
import { colors, spacing } from '../theme';
import type { Permit } from '../types';

type Props = NativeStackScreenProps<PermitsStackParamList, 'PermitsList'>;

export function PermitsListScreen({ navigation }: Props) {
  const [items, setItems] = useState<Permit[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api.get<{ data: Permit[] }>('/permits');
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

  const renderItem = ({ item }: { item: Permit }) => (
    <Card
      style={styles.row}
      onPress={() =>
        navigation.navigate('PermitDetail', {
          id: item.id,
          permitNumber: item.permit_number,
        })
      }
    >
      <View style={styles.rowMain}>
        <Text style={styles.number}>{item.permit_number}</Text>
        <Text style={styles.type} numberOfLines={1}>
          {item.permit_type?.name}
        </Text>
        <Text style={styles.validity}>
          Valid until {shortDate(item.valid_until)}
        </Text>
      </View>
      <StatusChip status={item.status} label={item.status_label} />
    </Card>
  );

  if (loading) return <LoadingView label="Loading permits…" />;
  if (error) return <ErrorView message={error} onRetry={load} />;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Text style={styles.title}>My Permits</Text>
      <FlatList
        data={items}
        keyExtractor={(it) => String(it.id)}
        renderItem={renderItem}
        contentContainerStyle={styles.list}
        ItemSeparatorComponent={() => <View style={{ height: spacing.md }} />}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => {
              setRefreshing(true);
              load();
            }}
            tintColor={colors.royal}
          />
        }
        ListEmptyComponent={
          <EmptyView
            icon="🎫"
            title="No permits yet"
            subtitle="Approved applications become permits and show up here."
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
    paddingBottom: spacing.md,
  },
  list: { padding: spacing.xl, paddingTop: spacing.xs, flexGrow: 1 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  rowMain: { flex: 1, marginRight: spacing.md },
  number: { color: colors.text, fontSize: 16, fontWeight: '800' },
  type: { color: colors.textMuted, fontSize: 14, marginTop: 2 },
  validity: { color: colors.textMuted, fontSize: 12, marginTop: 4 },
});
