import crypto from 'crypto';

export function hashIp(ip: string): string {
  return crypto
    .createHash('sha256')
    .update(ip + (process.env.NEXTAUTH_SECRET ?? 'salt'))
    .digest('hex');
}

export function parseDeviceType(userAgent: string): 'mobile' | 'tablet' | 'desktop' {
  const ua = userAgent.toLowerCase();
  if (/tablet|ipad|playbook|silk/.test(ua)) return 'tablet';
  if (/mobile|iphone|ipod|android|blackberry|opera mini|windows phone/.test(ua)) return 'mobile';
  return 'desktop';
}

export function getIpFromRequest(req: Request): string {
  return (
    req.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ??
    req.headers.get('x-real-ip') ??
    'unknown'
  );
}
