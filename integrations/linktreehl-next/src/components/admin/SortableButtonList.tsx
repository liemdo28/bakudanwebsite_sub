'use client';

import {
  DndContext, closestCenter, PointerSensor, useSensor, useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  SortableContext, verticalListSortingStrategy, useSortable, arrayMove,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { useState } from 'react';
import { Bars3Icon, PencilIcon, TrashIcon, StarIcon } from '@heroicons/react/24/outline';
import type { LinkButton } from '@prisma/client';

interface ButtonRowProps {
  btn: LinkButton;
  onEdit: (b: LinkButton) => void;
  onDelete: (id: number) => void;
}

function SortableRow({ btn, onEdit, onDelete }: ButtonRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition } = useSortable({ id: btn.id });
  const style = { transform: CSS.Transform.toString(transform), transition };

  return (
    <div ref={setNodeRef} style={style} className="flex items-center gap-3 bg-white border border-gray-200 rounded-lg p-3">
      <button {...listeners} {...attributes} className="cursor-grab text-gray-300 hover:text-gray-500 touch-none">
        <Bars3Icon className="w-5 h-5" />
      </button>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1">
          {(btn as any).is_featured && <StarIcon className="w-3 h-3 text-yellow-400 fill-yellow-400" />}
          <p className="font-medium text-sm text-gray-900 truncate">{btn.title}</p>
        </div>
        <p className="text-xs text-gray-400 truncate">{btn.url}</p>
      </div>
      <span className={`px-2 py-0.5 rounded text-xs font-medium ${
        btn.button_style === 'primary' ? 'bg-red-100 text-red-700' :
        btn.button_style === 'secondary' ? 'bg-blue-100 text-blue-700' :
        'bg-gray-100 text-gray-600'
      }`}>{btn.button_style}</span>
      <span className={`px-2 py-0.5 rounded-full text-xs ${btn.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400'}`}>
        {btn.is_active ? 'On' : 'Off'}
      </span>
      <button onClick={() => onEdit(btn)} className="text-gray-400 hover:text-blue-600"><PencilIcon className="w-4 h-4" /></button>
      <button onClick={() => onDelete(btn.id)} className="text-gray-400 hover:text-red-600"><TrashIcon className="w-4 h-4" /></button>
    </div>
  );
}

interface Props {
  pageId: number;
  initialButtons: LinkButton[];
  onEdit: (b: LinkButton) => void;
  onDelete: (id: number) => void;
  onReorder: (buttons: LinkButton[]) => void;
}

export default function SortableButtonList({ pageId, initialButtons, onEdit, onDelete, onReorder }: Props) {
  const [buttons, setButtons] = useState(initialButtons);
  const sensors = useSensors(useSensor(PointerSensor));

  async function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIndex = buttons.findIndex((b) => b.id === active.id);
    const newIndex = buttons.findIndex((b) => b.id === over.id);
    const reordered = arrayMove(buttons, oldIndex, newIndex);
    setButtons(reordered);
    onReorder(reordered);
    await fetch(`/api/admin/pages/${pageId}/buttons/reorder`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order: reordered.map((b) => b.id) }),
    });
  }

  const syncedButtons = initialButtons.length !== buttons.length ? initialButtons : buttons;

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
      <SortableContext items={syncedButtons.map((b) => b.id)} strategy={verticalListSortingStrategy}>
        <div className="flex flex-col gap-2">
          {syncedButtons.map((btn) => (
            <SortableRow key={btn.id} btn={btn} onEdit={onEdit} onDelete={onDelete} />
          ))}
        </div>
      </SortableContext>
    </DndContext>
  );
}
