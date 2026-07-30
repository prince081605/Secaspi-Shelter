import { api } from './api';

// The AspinPay checkout (see backend App\Services\SimulatedGateway). Every call is
// keyed by the session token from the /pay/:token URL; the backend re-checks that the
// donation behind that token belongs to the signed-in donor.

export async function getCheckout(token) {
  return api.get(`/api/payments/${token}`);
}

export async function authorizePayment(token, { account, pin }) {
  return api.post(`/api/payments/${token}/authorize`, { account, pin });
}

export async function confirmOtp(token, otp) {
  return api.post(`/api/payments/${token}/confirm`, { otp });
}

export async function cancelPayment(token) {
  return api.post(`/api/payments/${token}/cancel`);
}

// Issues a fresh checkout link for a donation that was never paid — used by
// "Complete payment" in the donation history and "Try again" after a decline.
export async function startCheckout(donationId) {
  return api.post(`/api/donations/${donationId}/checkout`);
}
