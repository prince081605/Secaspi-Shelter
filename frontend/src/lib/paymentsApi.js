import { api } from './api';

// The checkout API (backend App\Contracts\PaymentGateway). Every call is keyed by the
// session token from the /pay/:token URL; the backend re-checks that the donation behind
// that token belongs to the signed-in donor.

/**
 * Send the donor to a checkout link from the backend. With PayMongo that is their hosted
 * page on another site (an absolute URL), which the router cannot reach; the simulated
 * checkout is one of our own routes.
 */
export function goToCheckout(url, navigate, options) {
  if (/^https?:\/\//i.test(url)) {
    window.location.assign(url);
  } else {
    navigate(url, options);
  }
}

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
