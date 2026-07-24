import type { NavigatorScreenParams } from '@react-navigation/native';

// Track stack (applications list + detail).
export type TrackStackParamList = {
  TrackList: { note?: string } | undefined;
  ApplicationDetail: { id: number; trackingId?: string };
  Requests: undefined;
};

// Permits stack.
export type PermitsStackParamList = {
  PermitsList: undefined;
  PermitDetail: { id: number; permitNumber?: string };
};

// Bottom tabs.
export type TabParamList = {
  Home: undefined;
  Track: NavigatorScreenParams<TrackStackParamList> | undefined;
  Permits: NavigatorScreenParams<PermitsStackParamList> | undefined;
  Alerts: undefined;
};
