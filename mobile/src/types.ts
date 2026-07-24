// API resource shapes (subset used by the mobile client). See docs/api-contract.md.

export interface User {
  id: number;
  name: string;
  email?: string;
  role?: string;
}

export interface LoginResponse {
  token: string;
  user: User;
}

export interface BusinessRef {
  id: number;
  name: string;
}

export interface PermitTypeRef {
  code: string;
  name: string;
}

export interface ApplicationListItem {
  id: number;
  tracking_id: string;
  application_type: string;
  status: string;
  status_label?: string;
  business: BusinessRef;
  submitted_at?: string | null;
  deadline_at?: string | null;
  permit_types?: PermitTypeRef[];
  created_at?: string;
}

export interface FeeLineItem {
  label: string;
  amount: number | string;
}

export interface FeeAssessment {
  line_items: FeeLineItem[];
  total_amount: number | string;
}

export interface Payment {
  id: number;
  reference_number: string;
  amount: number | string;
  method: string;
  status: string;
  paid_at?: string | null;
}

export interface ApplicationDetail extends ApplicationListItem {
  applicant?: { id: number; name: string };
  fee_assessment?: FeeAssessment | null;
  payments?: Payment[];
  rejection_reason?: string | null;
  documents?: Array<{ id: number; original_filename: string }>;
}

export interface Permit {
  id: number;
  permit_number: string;
  status: string;
  status_label?: string;
  valid_from?: string | null;
  valid_until?: string | null;
  days_until_expiry?: number | null;
  permit_type: PermitTypeRef;
  business: BusinessRef;
  application?: { id: number; tracking_id: string };
  verify_url: string;
}

export interface Notification {
  id: number;
  type: string;
  title: string;
  body: string;
  link?: string | null;
  read_at?: string | null;
  created_at: string;
}

export interface OfficerRequest {
  id: number;
  request_type: 'document' | 'message';
  subject: string;
  body: string;
  status: string;
  status_label?: string;
  created_by?: { name: string; department?: string };
  application?: { id: number; tracking_id: string; business_name?: string };
  response_body?: string | null;
  created_at: string;
  responded_at?: string | null;
}
