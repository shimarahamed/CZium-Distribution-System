// src/components/ui/index.jsx
// Shared UI components — Tailwind CSS, shadcn/ui design tokens

// ─── Button ───────────────────────────────────────────────
export function Button({ children, variant = 'primary', size = 'md', className = '', disabled, onClick, type = 'button', ...props }) {
  const base = 'inline-flex items-center justify-center gap-2 font-medium transition-all duration-150 rounded-md focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed select-none';
  const variants = {
    primary:   'bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-border',
    ghost:     'hover:bg-accent hover:text-accent-foreground',
    danger:    'bg-destructive text-destructive-foreground hover:bg-destructive/90',
    success:   'bg-success text-success-foreground hover:bg-success/90',
    outline:   'border border-border hover:bg-accent hover:text-accent-foreground bg-transparent',
    link:      'text-primary hover:underline p-0 h-auto shadow-none',
  };
  const sizes = {
    xs: 'px-2 py-1 text-xs',
    sm: 'px-2.5 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
  };
  return (
    <button type={type} disabled={disabled} onClick={onClick}
      className={`${base} ${variants[variant] || variants.primary} ${sizes[size] || sizes.md} ${className}`} {...props}>
      {children}
    </button>
  );
}

// ─── Badge ────────────────────────────────────────────────
export function Badge({ children, variant = 'default', className = '' }) {
  const variants = {
    default:   'bg-primary/10 text-primary',
    success:   'bg-success/10 text-success',
    warning:   'bg-warning/10 text-warning',
    danger:    'bg-destructive/10 text-destructive',
    muted:     'bg-muted text-muted-foreground',
    outline:   'border border-border text-foreground',
  };
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${variants[variant] || variants.default} ${className}`}>
      {children}
    </span>
  );
}

// ─── Card ─────────────────────────────────────────────────
export function Card({ children, className = '', padding = true }) {
  return (
    <div className={`bg-card border border-border rounded-lg shadow-sm ${padding ? 'p-4' : ''} ${className}`}>
      {children}
    </div>
  );
}
export function CardHeader({ children, className = '' }) {
  return <div className={`flex items-center justify-between mb-4 ${className}`}>{children}</div>;
}
export function CardTitle({ children, className = '' }) {
  return <h3 className={`text-sm font-semibold text-foreground ${className}`}>{children}</h3>;
}

// ─── KPI Card ─────────────────────────────────────────────
export function KpiCard({ label, value, sub, icon, color = 'primary', trend, className = '' }) {
  const colors = {
    primary:     'text-primary bg-primary/10',
    success:     'text-success bg-success/10',
    warning:     'text-warning bg-warning/10',
    danger:      'text-destructive bg-destructive/10',
    purple:      'text-purple-500 bg-purple-500/10',
    cyan:        'text-cyan-500 bg-cyan-500/10',
  };
  return (
    <Card className={`flex items-start gap-4 ${className}`}>
      {icon && (
        <div className={`w-10 h-10 rounded-lg flex items-center justify-center text-lg shrink-0 ${colors[color] || colors.primary}`}>
          {icon}
        </div>
      )}
      <div className="min-w-0 flex-1">
        <p className="text-xs font-medium text-muted-foreground truncate">{label}</p>
        <p className="text-xl font-bold text-foreground mt-0.5 truncate">{value}</p>
        {(sub || trend) && (
          <p className="text-xs text-muted-foreground mt-0.5">
            {trend && <span className={trend > 0 ? 'text-success' : 'text-destructive'}>{trend > 0 ? '↑' : '↓'} {Math.abs(trend)}% </span>}
            {sub}
          </p>
        )}
      </div>
    </Card>
  );
}

// ─── Input ────────────────────────────────────────────────
export function Input({ label, error, className = '', ...props }) {
  return (
    <div className={className}>
      {label && <label className="block text-sm font-medium text-foreground mb-1.5">{label}</label>}
      <input
        className="w-full px-3 py-2 rounded-md border border-input bg-background text-sm text-foreground
                   placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring
                   focus:border-transparent transition-shadow"
        {...props}
      />
      {error && <p className="text-xs text-destructive mt-1">{error}</p>}
    </div>
  );
}

// ─── Select ───────────────────────────────────────────────
export function Select({ label, error, children, className = '', ...props }) {
  return (
    <div className={className}>
      {label && <label className="block text-sm font-medium text-foreground mb-1.5">{label}</label>}
      <select
        className="w-full px-3 py-2 rounded-md border border-input bg-background text-sm text-foreground
                   focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent
                   transition-shadow appearance-none cursor-pointer"
        {...props}
      >
        {children}
      </select>
      {error && <p className="text-xs text-destructive mt-1">{error}</p>}
    </div>
  );
}

// ─── Textarea ─────────────────────────────────────────────
export function Textarea({ label, error, className = '', rows = 3, ...props }) {
  return (
    <div className={className}>
      {label && <label className="block text-sm font-medium text-foreground mb-1.5">{label}</label>}
      <textarea rows={rows}
        className="w-full px-3 py-2 rounded-md border border-input bg-background text-sm text-foreground
                   placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring
                   focus:border-transparent transition-shadow resize-none"
        {...props}
      />
      {error && <p className="text-xs text-destructive mt-1">{error}</p>}
    </div>
  );
}

// ─── Modal ────────────────────────────────────────────────
export function Modal({ open, onClose, title, children, footer, size = 'md' }) {
  if (!open) return null;
  const sizes = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };
  return (
    <div className="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
      onClick={e => e.target === e.currentTarget && onClose?.()}>
      <div className={`bg-card rounded-xl shadow-lg border border-border w-full ${sizes[size] || sizes.md} max-h-[90vh] flex flex-col animate-in fade-in`}>
        {title && (
          <div className="flex items-center justify-between p-5 border-b border-border shrink-0">
            <h2 className="text-base font-semibold text-foreground">{title}</h2>
            <button onClick={onClose} className="text-muted-foreground hover:text-foreground transition-colors rounded-md p-1 hover:bg-accent">
              ✕
            </button>
          </div>
        )}
        <div className="overflow-y-auto flex-1 p-5">{children}</div>
        {footer && <div className="flex justify-end gap-3 p-5 border-t border-border shrink-0">{footer}</div>}
      </div>
    </div>
  );
}

// ─── Table ────────────────────────────────────────────────
export function Table({ columns, data, onRowClick, emptyText = 'No data', loading = false }) {
  if (loading) return (
    <div className="flex items-center justify-center py-16 text-muted-foreground text-sm gap-2">
      <Spinner size="sm" /> Loading...
    </div>
  );
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border bg-muted/50">
            {columns.map(col => (
              <th key={col.key} className={`px-4 py-2.5 text-left text-xs font-semibold text-muted-foreground uppercase tracking-wide whitespace-nowrap ${col.className || ''}`}>
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {!data?.length ? (
            <tr><td colSpan={columns.length} className="px-4 py-12 text-center text-muted-foreground text-sm">{emptyText}</td></tr>
          ) : data.map((row, i) => (
            <tr key={i}
              onClick={() => onRowClick?.(row)}
              className={`border-b border-border hover:bg-muted/30 transition-colors ${onRowClick ? 'cursor-pointer' : ''}`}>
              {columns.map(col => (
                <td key={col.key} className={`px-4 py-3 ${col.className || ''}`}>
                  {col.render ? col.render(row[col.key], row) : (row[col.key] ?? '—')}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ─── Spinner ──────────────────────────────────────────────
export function Spinner({ size = 'md' }) {
  const sizes = { sm: 'w-4 h-4', md: 'w-6 h-6', lg: 'w-8 h-8' };
  return (
    <svg className={`animate-spin text-primary ${sizes[size] || sizes.md}`} fill="none" viewBox="0 0 24 24">
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
    </svg>
  );
}

// ─── Empty State ──────────────────────────────────────────
export function EmptyState({ icon = '📭', title, description, action }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 text-center">
      <div className="text-4xl mb-3">{icon}</div>
      <h3 className="text-base font-semibold text-foreground mb-1">{title}</h3>
      {description && <p className="text-sm text-muted-foreground max-w-xs">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

// ─── Progress Bar ─────────────────────────────────────────
export function ProgressBar({ value, max = 100, color = 'primary', showLabel = false, size = 'sm' }) {
  const pct = Math.min(100, Math.round((value / max) * 100));
  const colors = {
    primary:  'bg-primary',
    success:  'bg-success',
    warning:  'bg-warning',
    danger:   'bg-destructive',
  };
  const heights = { xs: 'h-1', sm: 'h-1.5', md: 'h-2', lg: 'h-3' };
  return (
    <div className="flex items-center gap-2">
      <div className={`flex-1 ${heights[size] || heights.sm} bg-muted rounded-full overflow-hidden`}>
        <div className={`h-full rounded-full transition-all duration-500 ${colors[color] || colors.primary}`} style={{ width: `${pct}%` }} />
      </div>
      {showLabel && <span className="text-xs font-medium text-muted-foreground w-8 text-right">{pct}%</span>}
    </div>
  );
}

// ─── Bar Chart (CSS-only, no library needed) ──────────────
export function BarChart({ data, valueKey = 'value', labelKey = 'label', color = 'hsl(var(--primary))', format }) {
  if (!data?.length) return <EmptyState icon="📊" title="No data" />;
  const max = Math.max(...data.map(d => d[valueKey] || 0));
  const fmt = format || (v => v?.toLocaleString());
  return (
    <div className="space-y-2">
      {data.map((d, i) => (
        <div key={i} className="flex items-center gap-3">
          <span className="text-xs text-muted-foreground w-28 shrink-0 truncate text-right" title={d[labelKey]}>{d[labelKey]}</span>
          <div className="flex-1 h-5 bg-muted rounded overflow-hidden">
            <div className="h-full rounded transition-all duration-700" style={{ width: `${max ? ((d[valueKey] || 0) / max) * 100 : 0}%`, background: color }} />
          </div>
          <span className="text-xs font-semibold text-foreground w-20 shrink-0">{fmt(d[valueKey])}</span>
        </div>
      ))}
    </div>
  );
}

// ─── Tabs ─────────────────────────────────────────────────
export function Tabs({ tabs, active, onChange, className = '' }) {
  return (
    <div className={`flex gap-1 p-1 bg-muted rounded-lg ${className}`}>
      {tabs.map(tab => (
        <button key={tab.key} onClick={() => onChange(tab.key)}
          className={`flex items-center gap-2 px-3 py-1.5 rounded-md text-sm font-medium transition-all ${
            active === tab.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
          }`}>
          {tab.icon && <span>{tab.icon}</span>}
          {tab.label}
        </button>
      ))}
    </div>
  );
}

// ─── Page Header ──────────────────────────────────────────
export function PageHeader({ title, subtitle, actions, breadcrumb }) {
  return (
    <div className="flex items-start justify-between mb-6">
      <div>
        {breadcrumb && <p className="text-xs text-muted-foreground mb-1">{breadcrumb}</p>}
        <h1 className="text-xl font-bold text-foreground">{title}</h1>
        {subtitle && <p className="text-sm text-muted-foreground mt-0.5">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2 shrink-0 ml-4">{actions}</div>}
    </div>
  );
}

// ─── Status Badge helper ───────────────────────────────────
export function StatusBadge({ status }) {
  const map = {
    'Active':      'success', 'active': 'success', 'Completed': 'success',
    'Pending':     'warning', 'pending': 'warning', 'In Progress': 'warning', 'Draft': 'muted',
    'Cancelled':   'danger',  'cancelled': 'danger', 'Inactive': 'muted', 'Overdue': 'danger',
    'Planned':     'default', 'Paid': 'success', 'Unpaid': 'danger',
    'cash':        'success', 'credit': 'warning',
  };
  return <Badge variant={map[status] || 'muted'}>{status}</Badge>;
}

// ─── Search input ─────────────────────────────────────────
export function SearchInput({ value, onChange, placeholder = 'Search...', className = '' }) {
  return (
    <div className={`relative ${className}`}>
      <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground text-sm">🔍</span>
      <input
        type="text" value={value} onChange={e => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full pl-9 pr-4 py-2 rounded-md border border-input bg-background text-sm text-foreground
                   placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
      />
    </div>
  );
}

// ─── Toast (simple) ───────────────────────────────────────
export function Toast({ message, type = 'success', onClose }) {
  if (!message) return null;
  const types = { success: 'bg-success text-success-foreground', danger: 'bg-destructive text-destructive-foreground', warning: 'bg-warning text-warning-foreground', info: 'bg-primary text-primary-foreground' };
  return (
    <div className={`fixed bottom-4 right-4 z-[100] flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg text-sm font-medium max-w-sm ${types[type] || types.info}`}>
      {message}
      <button onClick={onClose} className="opacity-70 hover:opacity-100 ml-2">✕</button>
    </div>
  );
}
