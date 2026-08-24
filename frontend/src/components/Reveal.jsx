import React, { useEffect, useRef, useState } from "react";
import { motion, useInView } from "framer-motion";

export function Reveal({ children, delay = 0, className, as: Comp = "div" }) {
  const ref = useRef(null);
  const inView = useInView(ref, { once: true, margin: "-60px" });
  const MotionComp = motion[Comp] || motion.div;
  return (
    <MotionComp
      ref={ref}
      className={className}
      initial={{ opacity: 0, y: 22 }}
      animate={inView ? { opacity: 1, y: 0 } : {}}
      transition={{ duration: 0.6, delay, ease: [0.16, 1, 0.3, 1] }}
    >
      {children}
    </MotionComp>
  );
}

export function useCountUp() {
  const [v, setV] = useState(0);
  return [v, setV];
}

export default Reveal;
