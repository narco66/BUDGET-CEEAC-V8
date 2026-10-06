type CeeacMarkProps = {
    size?: number;
    label?: string;
    className?: string;
};

export default function CeeacMark({ size = 38, label = '', className = '' }: CeeacMarkProps) {
    return (
        <span className={['brand-mark', className].filter(Boolean).join(' ')} style={{ width: size, height: size }}>
            <img src="/logo-ceeac.png" alt={label} width={size} height={size} />
        </span>
    );
}
