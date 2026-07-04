'use strict';
const jwt = require('jsonwebtoken');

const SECRET = process.env.JWT_SECRET || 'bkdn-links-hub-jwt-secret-change-in-production';

function signToken(payload) {
  return jwt.sign(payload, SECRET, { expiresIn: '8h' });
}

function requireAuth(req, res, next) {
  const header = req.headers['authorization'] || '';
  const token  = header.startsWith('Bearer ') ? header.slice(7) : null;
  if (!token) return res.status(401).json({ ok: false, error: 'Unauthorized' });
  try {
    req.user = jwt.verify(token, SECRET);
    next();
  } catch {
    return res.status(401).json({ ok: false, error: 'Token invalid or expired' });
  }
}

function requireRole(...roles) {
  return [requireAuth, (req, res, next) => {
    if (roles.includes(req.user.role)) return next();
    return res.status(403).json({ ok: false, error: 'Forbidden' });
  }];
}

module.exports = { signToken, requireAuth, requireRole };
