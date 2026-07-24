import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  FlatList,
  RefreshControl,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api, apiError } from '../api';
import { Card } from '../components/Card';
import { PillButton } from '../components/PillButton';
import { EmptyView, ErrorView, LoadingView } from '../components/StateViews';
import { StatusChip } from '../components/StatusChip';
import { dateTime } from '../format';
import { colors, radius, spacing } from '../theme';
import type { OfficerRequest } from '../types';

// Requests still open for the owner to respond to.
function canRespond(status: string) {
  return (status || '').toLowerCase() === 'pending';
}

export function RequestsScreen() {
  const [items, setItems] = useState<OfficerRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [drafts, setDrafts] = useState<Record<number, string>>({});
  const [sending, setSending] = useState<number | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api.get<{ data: OfficerRequest[] }>('/requests');
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

  const respond = async (req: OfficerRequest) => {
    const body = (drafts[req.id] || '').trim();
    if (!body) {
      Alert.alert('Empty response', 'Please type a response first.');
      return;
    }
    setSending(req.id);
    try {
      await api.post(`/requests/${req.id}/respond`, { body });
      setDrafts((d) => ({ ...d, [req.id]: '' }));
      await load();
      Alert.alert('Sent', 'Your response was submitted to the officer.');
    } catch (e) {
      Alert.alert('Could not send', apiError(e));
    } finally {
      setSending(null);
    }
  };

  const renderItem = ({ item }: { item: OfficerRequest }) => (
    <Card style={styles.card}>
      <View style={styles.head}>
        <Text style={styles.subject} numberOfLines={2}>
          {item.subject}
        </Text>
        <StatusChip status={item.status} label={item.status_label} />
      </View>
      {item.application?.business_name || item.application?.tracking_id ? (
        <Text style={styles.meta}>
          {item.application?.business_name ?? ''}
          {item.application?.tracking_id ? ` · ${item.application.tracking_id}` : ''}
        </Text>
      ) : null}
      <Text style={styles.body}>{item.body}</Text>
      {item.created_by?.name ? (
        <Text style={styles.from}>
          From {item.created_by.name}
          {item.created_by.department ? ` · ${item.created_by.department}` : ''}
        </Text>
      ) : null}

      {item.response_body ? (
        <View style={styles.responseBox}>
          <Text style={styles.responseLabel}>Your response</Text>
          <Text style={styles.responseText}>{item.response_body}</Text>
          <Text style={styles.respTime}>{dateTime(item.responded_at)}</Text>
        </View>
      ) : canRespond(item.status) ? (
        <View style={styles.respondArea}>
          <TextInput
            style={styles.input}
            placeholder="Type your response…"
            placeholderTextColor={colors.textMuted}
            multiline
            value={drafts[item.id] ?? ''}
            onChangeText={(t) => setDrafts((d) => ({ ...d, [item.id]: t }))}
          />
          <PillButton
            title="Send response"
            onPress={() => respond(item)}
            loading={sending === item.id}
            style={styles.sendBtn}
          />
        </View>
      ) : null}
    </Card>
  );

  if (loading) return <LoadingView label="Loading requests…" />;
  if (error) return <ErrorView message={error} onRetry={load} />;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Text style={styles.title}>Other Requirements</Text>
      <Text style={styles.sub}>
        Requests from officers for additional documents or information.
      </Text>
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
            icon="🛡️"
            title="No open requests"
            subtitle="Officers may ask for extra requirements here."
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
  sub: {
    color: colors.textMuted,
    fontSize: 14,
    paddingHorizontal: spacing.xl,
    marginTop: spacing.xs,
  },
  list: { padding: spacing.xl, flexGrow: 1 },
  card: {},
  head: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
  },
  subject: {
    flex: 1,
    marginRight: spacing.md,
    fontSize: 16,
    fontWeight: '700',
    color: colors.text,
  },
  meta: { color: colors.textMuted, fontSize: 12, marginTop: spacing.xs },
  body: { color: colors.text, fontSize: 14, lineHeight: 20, marginTop: spacing.sm },
  from: { color: colors.textMuted, fontSize: 12, marginTop: spacing.sm },
  respondArea: { marginTop: spacing.md },
  input: {
    backgroundColor: colors.input,
    borderRadius: radius.input,
    padding: spacing.md,
    minHeight: 72,
    textAlignVertical: 'top',
    color: colors.text,
    fontSize: 14,
  },
  sendBtn: { marginTop: spacing.md },
  responseBox: {
    marginTop: spacing.md,
    backgroundColor: colors.canvas,
    borderRadius: radius.input,
    padding: spacing.md,
  },
  responseLabel: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  responseText: { color: colors.text, fontSize: 14, marginTop: spacing.xs, lineHeight: 20 },
  respTime: { color: colors.textMuted, fontSize: 11, marginTop: spacing.xs },
});
