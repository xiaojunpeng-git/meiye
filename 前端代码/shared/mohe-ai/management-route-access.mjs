/**
 * Navigation must follow the server's current principal for the one
 * configuration-only entry. A failed configuration read is not evidence that
 * the person lacks permission, while an explicit denial is.
 */
export async function managementRouteAccess(request) {
  try {
    await request('GET', '/management');
    return { allowed: true, serverVerified: true };
  } catch (error) {
    if (error && error.reason === 'AI_PERMISSION_DENIED') {
      return { allowed: false, serverVerified: false };
    }
    return { allowed: true, serverVerified: false };
  }
}
