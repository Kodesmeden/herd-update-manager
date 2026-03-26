import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { ReactNode } from 'react';

interface SimpleTooltipProps {
    content: string;
    children: ReactNode;
    side?: 'top' | 'bottom' | 'left' | 'right';
}

export default function SimpleTooltip({ content, children, side = 'top' }: SimpleTooltipProps) {
    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>{children}</TooltipTrigger>
                <TooltipContent side={side}>{content}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
