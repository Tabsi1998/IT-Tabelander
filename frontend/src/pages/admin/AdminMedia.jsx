import React, { useEffect, useRef, useState } from "react";
import { Upload, Trash2, Loader2, Copy, Check } from "lucide-react";
import { toast } from "sonner";
import api, { mediaUrl } from "../../lib/api";
import Skeleton from "../../components/ui/skeleton";
import { AdminHeader, Empty } from "../../components/admin/AdminUI";
import { Button } from "../../components/ui/button";

export default function AdminMedia() {
  const [items, setItems] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [copied, setCopied] = useState(null);
  const fileRef = useRef();

  const load = () => api.get("/admin/media").then(({ data }) => setItems(data)).catch(() => setItems([]));
  useEffect(() => { load(); }, []);

  const onFiles = async (e) => {
    const files = Array.from(e.target.files || []);
    if (!files.length) return;
    setUploading(true);
    try {
      for (const file of files) {
        const fd = new FormData();
        fd.append("file", file);
        fd.append("alt", file.name);
        await api.post("/admin/media", fd);
      }
      toast.success("Hochgeladen");
      load();
    } catch { toast.error("Upload fehlgeschlagen"); } finally { setUploading(false); if (fileRef.current) fileRef.current.value = ""; }
  };

  const del = async (id) => { if (!window.confirm("Löschen?")) return; await api.delete(`/admin/media/${id}`); load(); };

  const copy = (url) => { navigator.clipboard.writeText(url); setCopied(url); setTimeout(() => setCopied(null), 1500); };

  return (
    <>
      <AdminHeader title="Medien" desc="Bilder hochladen und verwalten (werden serverseitig als WebP optimiert)"
        action={
          <Button onClick={() => fileRef.current?.click()} disabled={uploading} data-testid="media-upload-btn">
            {uploading ? <Loader2 className="animate-spin" size={16} /> : <Upload size={16} />} Hochladen
          </Button>
        } />
      <input ref={fileRef} type="file" accept="image/*" multiple className="hidden" onChange={onFiles} data-testid="media-file-input" />

      {!items ? <Skeleton className="h-64" /> : items.length === 0 ? <Empty>Noch keine Medien hochgeladen.</Empty> : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {items.map((m) => (
            <div key={m.id} className="group overflow-hidden rounded-xl border border-subtle bg-surface">
              <div className="aspect-square overflow-hidden bg-black/30">
                <img src={mediaUrl(m.url)} alt={m.alt} className="h-full w-full object-cover" loading="lazy" />
              </div>
              <div className="p-2">
                <p className="truncate text-xs text-muted" title={m.alt}>{m.alt || m.filename}</p>
                <div className="mt-2 flex justify-between">
                  <button onClick={() => copy(mediaUrl(m.url))} className="rounded p-1.5 text-faint hover:text-brand" title="URL kopieren">{copied === mediaUrl(m.url) ? <Check size={14} className="text-emerald-400" /> : <Copy size={14} />}</button>
                  <button onClick={() => del(m.id)} className="rounded p-1.5 text-faint hover:text-red-400"><Trash2 size={14} /></button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  );
}
