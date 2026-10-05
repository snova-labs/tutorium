import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

interface ProvenanceChipProps {
  /** The effective value, as it should be read. */
  value: string;
  /** True when the value comes from a level above; false when it was set here. */
  inherited: boolean;
  /** Where an inherited value comes from: "Kathmandu branch". */
  inheritedFrom?: string;
  /** What "here" is: "this batch". */
  setOn?: string;
  /** Monospaced for identifiers such as timezones and codes. */
  mono?: boolean;
}

/**
 * Where a value came from, at a glance (SL-ARC-002 §7).
 *
 * Grey when the value is inherited from a level above, amber when it was overridden at this level.
 * Amber is reserved for this one job across the product, so it always means "someone changed this
 * here". The origin is also given in words (tooltip and screen-reader text), never by colour alone.
 */
export function ProvenanceChip({ value, inherited, inheritedFrom, setOn = "here", mono = true }: ProvenanceChipProps) {
  const origin = inherited ? `Inherited${inheritedFrom ? ` from ${inheritedFrom}` : ""}` : `Set on ${setOn}`;

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge
          variant={inherited ? "secondary" : "override"}
          className={mono ? "font-mono" : undefined}
          data-provenance={inherited ? "inherited" : "overridden"}
        >
          {value}
          <span className="sr-only">. {origin}.</span>
        </Badge>
      </TooltipTrigger>
      <TooltipContent>{origin}</TooltipContent>
    </Tooltip>
  );
}
