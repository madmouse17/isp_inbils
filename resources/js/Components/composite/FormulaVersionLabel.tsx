interface FormulaVersionLabelProps {
    version: string | null | undefined;
}

export function FormulaVersionLabel({ version }: FormulaVersionLabelProps) {
    if (!version) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            Versi rumus <span className="font-medium text-foreground">{version}</span>
        </p>
    );
}
