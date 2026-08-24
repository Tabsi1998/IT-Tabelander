import {
  Cpu, Wrench, Laptop, ArrowUpCircle, Gamepad2, Gamepad, HardDrive,
  MemoryStick, MonitorSmartphone, Zap, Fan, ShieldCheck, Settings, Server, CircuitBoard,
} from "lucide-react";

export const ICONS = {
  cpu: Cpu,
  wrench: Wrench,
  laptop: Laptop,
  "arrow-up-circle": ArrowUpCircle,
  "gamepad-2": Gamepad2,
  gamepad: Gamepad,
  harddrive: HardDrive,
  memory: MemoryStick,
  monitor: MonitorSmartphone,
  zap: Zap,
  fan: Fan,
  shield: ShieldCheck,
  settings: Settings,
  server: Server,
  circuit: CircuitBoard,
};

export function iconFor(name) {
  return ICONS[name] || Wrench;
}
